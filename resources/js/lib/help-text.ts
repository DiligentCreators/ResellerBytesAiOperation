/**
 * Central copy for InfoTip tooltips. Keep each string under ~140 characters.
 * No em-dashes.
 */
export const helpText = {
    healthScore:
        '100 means nothing needs attention. It drops when we find problems and rises when you fix them.',
    timeSaved:
        'Time we estimate you saved from fixes we confirmed. Change the hourly value in Settings > Account.',
    connectionBadge:
        'Online: we heard from your WHMCS in the last 30 minutes. Quiet: 30 to 90 minutes. Offline: more than 90 minutes.',
    needsYourAttention: 'The most urgent problems first.',
    severityCritical:
        'Fix this soon. It can stop billing, logins, or put your site at risk.',
    severityWarning: 'Not urgent but worth fixing this week.',
    severityInfo: 'Good to know. No action needed.',
    fixItForMe:
        'We apply a safe, pre-approved change in WHMCS after you say yes. Nothing happens without your approval.',
    showMeHow: 'Step-by-step instructions you do yourself in WHMCS.',
    accepted:
        'You know about this and choose to leave it. It stops counting against your score.',
    checkAgain: 'We re-check your WHMCS to confirm the problem is gone.',
    connectionKey:
        'Shown once. It lets the add-on prove it is really your WHMCS. Never paste it into WHMCS settings or chat.',
    connectionFile:
        'A small file on your server with your connection ID and key. Keep it outside the public web folder.',
    scheduledTask:
        "WHMCS already runs a task every few minutes. The add-on uses it to say 'I am alive' to us.",
    automaticChecks:
        'How often we automatically look at your WHMCS. Default every 60 minutes.',
    backupConnection:
        'Optional. Only used when a fix cannot be applied directly. You can skip it.',
    downloadFingerprint:
        'Compare this code with the one shown here to make sure the file was not changed.',
    fixLogHowFixed:
        'One click = we did it. Step by step = you did it using our guide.',
    twoFactor:
        'A code from your phone app at login. Recommended. You can make it required for your team in Settings.',
    roles: 'Owner: everything. Admin: manage connections and fixes. Operator: view and fix. Viewer: view only. Billing: invoices only.',
    requireTwoFactor: 'Everyone on your team must set up the phone code.',
    planLimits: 'How many WHMCS sites, team members and checks you get.',
    webhook:
        'Sends a message to another tool you use. Most people only need email or Slack.',
    paidInWhmcs:
        'Total of invoices marked paid in your WHMCS in the last 7 days, from your latest check.',
} as const;

export type HelpTextKey = keyof typeof helpText;
