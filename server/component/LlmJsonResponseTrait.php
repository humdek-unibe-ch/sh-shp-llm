<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Trait for sending JSON responses in LLM controllers.
 * Provides consistent response handling across chat, admin, and script controllers.
 */
trait LlmJsonResponseTrait
{
    /**
     * Send JSON response and exit.
     * Override beforeSendJsonResponse() in the class to add custom logic (e.g. activity logging).
     *
     * @param array $data Response data
     * @param int $status_code HTTP status code
     */
    protected function sendJsonResponse($data, $status_code = 200)
    {
        $this->beforeSendJsonResponse();

        if (!headers_sent()) {
            http_response_code($status_code);
            header('Content-Type: application/json');
        }

        // Log user activity before exiting so it is recorded in user_activity table.
        $this->model->get_services()->get_router()->log_user_activity();

        echo json_encode($data);

        if (function_exists('uopz_allow_exit')) {
            uopz_allow_exit(true);
        }
        exit;
    }

    /**
     * Hook called before sending JSON response.
     * Override in class to add custom logic (e.g. API activity logging).
     */
    protected function beforeSendJsonResponse()
    {
        // Default: no-op. Override in subclasses as needed.
    }
}
