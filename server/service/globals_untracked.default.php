<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Local configuration template of the LLM plugin.
 *
 * Copy this file to `globals_untracked.php` (same folder) and uncomment what
 * you need. `globals_untracked.php` is ignored by git; never commit it.
 *
 * The file is OPTIONAL. Without it the plugin loads and runs with its
 * defaults. Every value below can also be provided as an environment
 * variable of the same name (recommended for production: nothing secret is
 * written to disk). When both are set, the precedence is stated per option.
 *
 * Nothing in this template is active until you uncomment it, so copying it
 * unchanged is harmless.
 */

/* -------------------------------------------------------------------------
 * LLM_SECRET_KEY - master secret for the encrypted API keys
 *
 * With a secret of at least 16 characters, API keys saved on the settings
 * page are stored as `enc:v1:...` (AES-256-GCM) instead of plain text.
 * Without it keys are stored as entered. Do not change or lose it: keys
 * encrypted with a different secret cannot be read any more and have to be
 * entered again. If the environment variable LLM_SECRET_KEY is also set, the
 * environment variable wins over this constant.
 *
 * Generate one:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 * ---------------------------------------------------------------------- */
// define('LLM_SECRET_KEY', 'replace-with-at-least-16-random-characters');

/* -------------------------------------------------------------------------
 * LLM_ALLOWED_API_HOSTS - optional allow-list of API hosts
 *
 * When set, the base URL of every API server must point to one of these
 * hosts (exact host names, no scheme or path). Leave it unset to allow any
 * https host (plain http stays limited to localhost / 127.0.0.1 / ::1).
 * Array, or a comma separated string when set as environment variable. If
 * both are set, this constant wins over the environment variable.
 * ---------------------------------------------------------------------- */
// define('LLM_ALLOWED_API_HOSTS', array('api.openai.com', 'llm.example.org'));
