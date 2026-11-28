# WHMCS addon changelog

## 1.2.4

- Trust empty GetModuleQueue API success (emit zero `module_queue` block; no table fallback).
- Fix `tblmodulequeue` column `last_attempt_error` (was incorrect `last_error`).
- Record/log sanitized module-queue collection failures; emit `module_queue_unavailable` when both API and table fail.

## 1.2.3

- Emit five-key integer `module_queue.by_action` for schema 1.1 ingestion.
