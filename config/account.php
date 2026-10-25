<?php

/*
| Customer accounts. Links in account emails open the React storefront,
| which then calls the API (see app/Services/Account/AccountLinks.php).
*/

$frontendUrls = array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URLS', ''))));

return [
    // Public storefront origin used in emails, e.g. https://www.pdlafrica.com
    // (no trailing slash). Defaults to the first FRONTEND_URLS entry.
    'frontend_url' => rtrim((string) (env('FRONTEND_URL') ?: (reset($frontendUrls) ?: 'http://localhost:5173')), '/'),

    // Lifetime of email verification and email-change links, in minutes.
    // Password reset links use auth.passwords.users.expire.
    'link_expire_minutes' => (int) env('ACCOUNT_LINK_EXPIRE_MINUTES', 60),
];
