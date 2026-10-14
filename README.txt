BREAKPOINT HEALTHCARE - Phase 1 starter (HTML, CSS, JS, PHP 8+, MySQL)

1. Put the folder in htdocs (XAMPP) or your web root.
2. In phpMyAdmin, DROP the old 'breakpoint' database, then import database.sql (schema changed).
3. Edit DB_* values at the top of config.php.
4. Open http://localhost/breakpoint/setup.php once, then DELETE setup.php.
5. Open http://localhost/breakpoint/

Demo staff (password ChangeMe123!): admin 0700000001, doctor 0700000002
Patients create their own accounts on the home page (login = phone number).

FLOW
patient starts consultation -> rule-based triage (RED = emergency) -> patient pays via M-Pesa and submits code
-> admin confirms payment -> doctor accepts from queue (emergency cases skip payment) -> SOAP notes,
prescriptions, lab requests -> doctor completes -> admin marks prescriptions dispensed at a pharmacy.

TODO (placeholders in this version)
- triage(): keyword rules in api.php; replace with your AI bot.
- M-Pesa: manual code + admin confirmation; replace with Daraja STK push + callback.
- agora_channel_id is generated, but no Agora token/video yet.
Before going live: HTTPS, change staff passwords, CSRF tokens, rate limiting.
