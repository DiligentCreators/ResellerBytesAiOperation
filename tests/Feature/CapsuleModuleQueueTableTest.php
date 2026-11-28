<?php

use Illuminate\Database\Capsule\Manager as CapsuleManager;
use ResellerBytesAiOps\ActivityLogger;
use ResellerBytesAiOps\CapsuleWhmcsDataSource;
use WHMCS\Database\Capsule;

/**
 * In-memory sqlite coverage for CapsuleWhmcsDataSource::moduleQueueFromTable()
 * against the real WHMCS tblmodulequeue column set (last_attempt_error).
 *
 * Isolates WHMCS\Database\Capsule via class_alias only when absent, and
 * restores no global Capsule connection afterward by using a dedicated
 * connection name that other Pest tests do not share.
 */

require_once dirname(__DIR__, 2).'/whmcs-addon/modules/addons/resellerbytes_ai_ops/lib/Text.php';
require_once dirname(__DIR__, 2).'/whmcs-addon/modules/addons/resellerbytes_ai_ops/lib/ActivityLogger.php';
require_once dirname(__DIR__, 2).'/whmcs-addon/modules/addons/resellerbytes_ai_ops/lib/WhmcsActivityLogger.php';
require_once dirname(__DIR__, 2).'/whmcs-addon/modules/addons/resellerbytes_ai_ops/lib/WhmcsDataSource.php';
require_once dirname(__DIR__, 2).'/whmcs-addon/modules/addons/resellerbytes_ai_ops/lib/CapsuleWhmcsDataSource.php';

final class RecordingModuleQueueLogger implements ActivityLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log(string $message): void
    {
        $this->messages[] = $message;
    }
}

function bootModuleQueueCapsule(string $connectionName): CapsuleManager
{
    $capsule = new CapsuleManager;
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ], $connectionName);
    $capsule->getDatabaseManager()->setDefaultConnection($connectionName);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    if (! class_exists(Capsule::class, false)) {
        class_alias(CapsuleManager::class, 'WHMCS\\Database\\Capsule');
    }

    return $capsule;
}

function createRealModuleQueueTable(CapsuleManager $capsule): void
{
    $schema = $capsule->schema();
    $schema->dropIfExists('tblmodulequeue');
    $schema->create('tblmodulequeue', function ($table): void {
        $table->increments('id');
        $table->string('service_type');
        $table->unsignedInteger('service_id');
        $table->string('module_name')->nullable();
        $table->string('module_action');
        $table->dateTime('last_attempt')->nullable();
        $table->text('last_attempt_error')->nullable();
        $table->unsignedInteger('num_retries')->default(0);
        $table->unsignedTinyInteger('completed')->default(0);
        $table->timestamps();
    });
}

test('moduleQueueFromTable returns pending rows using last_attempt_error', function () {
    $connection = 'rb_module_queue_'.bin2hex(random_bytes(4));
    $capsule = bootModuleQueueCapsule($connection);
    createRealModuleQueueTable($capsule);

    $now = gmdate('Y-m-d H:i:s');
    $capsule->table('tblmodulequeue')->insert([
        [
            'service_type' => 'service',
            'service_id' => 101,
            'module_name' => 'cpanel',
            'module_action' => 'CreateAccount',
            'last_attempt' => $now,
            'last_attempt_error' => 'timeout contacting panel',
            'num_retries' => 1,
            'completed' => 0,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 600),
            'updated_at' => $now,
        ],
        [
            'service_type' => 'service',
            'service_id' => 102,
            'module_name' => 'cpanel',
            'module_action' => 'SuspendAccount',
            'last_attempt' => $now,
            'last_attempt_error' => 'already done',
            'num_retries' => 0,
            'completed' => 1,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 120),
            'updated_at' => $now,
        ],
        [
            'service_type' => 'domain',
            'service_id' => 55,
            'module_name' => 'registrar',
            'module_action' => 'RegisterDomain',
            'last_attempt' => $now,
            'last_attempt_error' => 'registrar rejected',
            'num_retries' => 2,
            'completed' => 0,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 300),
            'updated_at' => $now,
        ],
    ]);

    $logger = new RecordingModuleQueueLogger;
    $source = new CapsuleWhmcsDataSource($logger);
    $rows = $source->moduleQueueFromTable();

    expect($rows)->toBeArray()
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]['service_id'])->toBe(101)
        ->and($rows[0]['error'])->toBe('timeout contacting panel')
        ->and($rows[1]['service_id'])->toBe(55)
        ->and($rows[1]['error'])->toBe('registrar rejected')
        ->and($source->lastModuleQueueError())->toBeNull()
        ->and($logger->messages)->toBeEmpty();

    $capsule->schema()->dropIfExists('tblmodulequeue');
    $capsule->getDatabaseManager()->purge($connection);
});

test('moduleQueueFromTable returns empty list when table has no pending rows', function () {
    $connection = 'rb_module_queue_empty_'.bin2hex(random_bytes(4));
    $capsule = bootModuleQueueCapsule($connection);
    createRealModuleQueueTable($capsule);

    $logger = new RecordingModuleQueueLogger;
    $source = new CapsuleWhmcsDataSource($logger);
    $rows = $source->moduleQueueFromTable();

    expect($rows)->toBe([])
        ->and($source->lastModuleQueueError())->toBeNull()
        ->and($logger->messages)->toBeEmpty();

    $capsule->schema()->dropIfExists('tblmodulequeue');
    $capsule->getDatabaseManager()->purge($connection);
});

test('moduleQueueFromTable returns null and records a sanitized error on query failure', function () {
    // SQLite treats missing double-quoted identifiers as string literals, so a
    // table that merely omits last_attempt_error does not throw under Illuminate.
    // Drop the table instead to exercise the Throwable/null path; the happy-path
    // tests above prove MODULE_QUEUE_COLUMNS maps last_attempt_error -> error.
    $connection = 'rb_module_queue_bad_'.bin2hex(random_bytes(4));
    $capsule = bootModuleQueueCapsule($connection);
    $capsule->schema()->dropIfExists('tblmodulequeue');

    expect(CapsuleWhmcsDataSource::MODULE_QUEUE_COLUMNS)->toContain('last_attempt_error')
        ->and(CapsuleWhmcsDataSource::MODULE_QUEUE_COLUMNS)->not->toContain('last_error');

    $logger = new RecordingModuleQueueLogger;
    $source = new CapsuleWhmcsDataSource($logger);
    $rows = $source->moduleQueueFromTable();

    expect($rows)->toBeNull()
        ->and($source->lastModuleQueueError())->not->toBeNull()
        ->and($logger->messages)->toHaveCount(1)
        ->and($logger->messages[0])->toContain('module queue collection failed')
        ->and($logger->messages[0])->not->toContain('SELECT')
        ->and($logger->messages[0])->not->toContain('tblmodulequeue');

    $capsule->getDatabaseManager()->purge($connection);
});
