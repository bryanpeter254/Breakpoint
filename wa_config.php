<?php
// Secrets for the WhatsApp triage bot. Prefer real environment variables in production.
// Never commit this file or expose it on a public repo.
define('WA_VERIFY_TOKEN',     getenv('WA_VERIFY_TOKEN')     ?: 'choose-any-long-random-string'); // you type the same one in Meta's webhook setup
define('WA_APP_SECRET',       getenv('WA_APP_SECRET')       ?: 'META_APP_SECRET');              // Meta app > Settings > Basic
define('WA_TOKEN',            getenv('WA_TOKEN')            ?: 'WHATSAPP_PERMANENT_ACCESS_TOKEN');
define('WA_PHONE_NUMBER_ID',  getenv('WA_PHONE_NUMBER_ID')  ?: 'WHATSAPP_PHONE_NUMBER_ID');
define('ANTHROPIC_API_KEY',   getenv('ANTHROPIC_API_KEY')   ?: 'sk-ant-...');
define('ANTHROPIC_MODEL',     'claude-sonnet-5-5');
define('WA_SITE_URL',         'https://your-domain.co.ke/breakpoint/');
define('WA_PAY_INSTRUCTIONS', 'Till/Paybill XXXXXX');  // shown to patients
