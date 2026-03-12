<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/../../../../ajax/BaseAjax.php';
require_once __DIR__ . '/../service/LlmPromptRegistryService.php';
require_once __DIR__ . '/../service/LlmPromptPlaygroundService.php';
require_once __DIR__ . '/../service/LlmPromptBuilderService.php';
require_once __DIR__ . '/../service/LlmPromptExecutionProfileService.php';
require_once __DIR__ . '/../service/LlmScriptService.php';

class AjaxLlmPromptLab extends BaseAjax
{
    /** @var LlmPromptRegistryService */
    private $registry_service;

    /** @var LlmPromptPlaygroundService */
    private $playground_service;

    /** @var LlmPromptBuilderService */
    private $builder_service;

    /** @var LlmPromptExecutionProfileService */
    private $profile_service;

    /** @var LlmScriptService */
    private $script_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->registry_service = new LlmPromptRegistryService($services);
        $this->playground_service = new LlmPromptPlaygroundService($services);
        $this->builder_service = new LlmPromptBuilderService($services);
        $this->profile_service = new LlmPromptExecutionProfileService($services);
        $this->script_service = new LlmScriptService($services);
    }

    public function dispatch($post)
    {
        $action = $post['action'] ?? '';
        $descriptor = $this->readDescriptor($post);

        switch ($action) {
            case 'bootstrap_owner':
                $this->assertAccess($descriptor, 'select');
                return $this->handleBootstrap($post, $descriptor);

            case 'get_version':
                $this->assertAccess($descriptor, 'select');
                return $this->handleGetVersion($post);

            case 'list_versions':
                $this->assertAccess($descriptor, 'select');
                return $this->handleListVersions($post, $descriptor);

            case 'playground_run':
                $this->assertAccess($descriptor, 'update');
                $this->assertCsrf($post);
                return $this->handlePlaygroundRun($post, $descriptor);

            case 'builder_run':
                $this->assertAccess($descriptor, 'update');
                $this->assertCsrf($post);
                return $this->handleBuilderRun($post, $descriptor);
        }

        throw new Exception('Unknown prompt lab action: ' . $action);
    }

    private function handleBootstrap($post, $descriptor)
    {
        $runtime_values = $this->resolveRuntimeValues($descriptor, $post);
        return $this->registry_service->bootstrapOwner(
            $descriptor,
            (string)($post['current_content'] ?? ''),
            $post['current_meta'] ?? null,
            $this->canMutate($descriptor),
            $runtime_values
        );
    }

    private function handleGetVersion($post)
    {
        $version_id = isset($post['version_id']) ? (int)$post['version_id'] : 0;
        if ($version_id <= 0) {
            throw new Exception('Missing version_id');
        }

        $version = $this->registry_service->getVersion($version_id);
        if (!$version) {
            throw new Exception('Prompt version not found');
        }

        return $version;
    }

    private function handleListVersions($post, $descriptor)
    {
        $bootstrap = $this->handleBootstrap($post, $descriptor);
        return array(
            'versions' => $bootstrap['versions'] ?? array(),
            'active_version' => $bootstrap['active_version'] ?? null
        );
    }

    private function handlePlaygroundRun($post, $descriptor)
    {
        $runtime_values = $this->resolveRuntimeValues($descriptor, $post);
        $variables = $this->decodeJson($post['variables_json'] ?? '{}');
        $message_history = $this->decodeJson($post['message_history_json'] ?? '[]');
        $selected_models = $this->decodeJson($post['selected_models_json'] ?? '[]');

        return $this->playground_service->run(
            $descriptor,
            (string)($post['draft_prompt'] ?? ''),
            $runtime_values,
            is_array($variables) ? $variables : array(),
            is_array($message_history) ? $message_history : array(),
            is_array($selected_models) ? $selected_models : array()
        );
    }

    private function handleBuilderRun($post, $descriptor)
    {
        $result = $this->builder_service->buildSuggestion(
            $descriptor,
            (string)($post['current_prompt'] ?? ''),
            (string)($post['instructions'] ?? ''),
            !empty($post['selected_model']) ? $post['selected_model'] : null
        );

        $bootstrap = $this->registry_service->bootstrapOwner($descriptor);
        $this->registry_service->logPlaygroundRun(array(
            'id_llm_prompt_entries' => $bootstrap['entry']['id'] ?? null,
            'id_llm_prompt_locales' => $bootstrap['locale']['id'] ?? null,
            'id_llm_prompt_versions' => $bootstrap['active_version']['id'] ?? null,
            'id_llmConversations' => $result['id_llmConversations'] ?? null,
            'id_llmMessages_request' => $result['id_llmMessages_request'] ?? null,
            'id_llmMessages_response' => $result['id_llmMessages_response'] ?? null,
            'run_mode' => LLM_PROMPT_RUN_MODE_BUILDER,
            'variables_json' => array(
                'instructions' => (string)($post['instructions'] ?? '')
            ),
            'config_snapshot_json' => array(
                'model' => $result['model'] ?? null
            )
        ));

        return $result;
    }

    private function readDescriptor($post)
    {
        return array(
            'owner_type' => $post['owner_type'] ?? '',
            'owner_id' => isset($post['owner_id']) ? (int)$post['owner_id'] : 0,
            'prompt_slot' => $post['prompt_slot'] ?? '',
            'id_languages' => ($post['id_languages'] ?? '') !== '' ? (int)$post['id_languages'] : null,
            'page_id' => isset($post['page_id']) ? (int)$post['page_id'] : null,
            'title' => $post['title'] ?? null
        );
    }

    private function resolveRuntimeValues($descriptor, $post)
    {
        $profile = $this->profile_service->resolveExecutionProfile($descriptor);
        $overrides = $this->decodeJson($post['runtime_overrides_json'] ?? '{}');
        if (!is_array($overrides)) {
            $overrides = array();
        }

        if (($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_SCRIPT) {
            $script = $this->script_service->fetch_script((int)$descriptor['owner_id']);
            $runtime_values = is_array($script) ? $script : array();
        } else {
            $field_names = $this->profile_service->getCompanionFieldNames($profile);
            $runtime_values = $this->profile_service->getStyleFieldValues(
                (int)$descriptor['owner_id'],
                $descriptor['id_languages'] ?? null,
                $field_names
            );
        }

        foreach ($overrides as $key => $value) {
            $runtime_values[$key] = $value;
        }

        return $runtime_values;
    }

    private function assertAccess($descriptor, $mode)
    {
        if (($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_SCRIPT) {
            $page_id = $this->db->fetch_page_id_by_keyword(LLM_SCRIPTS_PAGE_KEYWORD);
            $method = 'has_access_' . $mode;
            if (!$page_id || !$this->acl->$method($_SESSION['id_user'], $page_id)) {
                throw new Exception('Access denied');
            }
            return;
        }

        $page_id = (int)($descriptor['page_id'] ?? 0);
        if ($page_id <= 0 && (int)($descriptor['owner_id'] ?? 0) > 0) {
            $resolved = $this->db->query_db_first(
                "SELECT id_pages FROM sections WHERE id = :id LIMIT 1",
                array(':id' => (int)$descriptor['owner_id'])
            );
            if (!empty($resolved['id_pages'])) {
                $page_id = (int)$resolved['id_pages'];
            }
        }
        if ($page_id <= 0) {
            throw new Exception('Missing page context');
        }

        $method = 'has_access_' . $mode;
        if (!$this->acl->$method($_SESSION['id_user'], $page_id)) {
            throw new Exception('Access denied');
        }
    }

    private function canMutate($descriptor)
    {
        try {
            $this->assertAccess($descriptor, 'update');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function assertCsrf($post)
    {
        $token = $post['csrf_token']
            ?? $post['token']
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        $session_tokens = array_values(array_filter(array(
            $_SESSION['csrf_token'] ?? '',
            $_SESSION['token'] ?? '',
            $_SESSION['security_token'] ?? ''
        ), function ($value) {
            return is_string($value) && trim($value) !== '';
        }));

        // Some installations do not expose a session CSRF token to plugin AJAX.
        // In that case keep ACL protection and skip token comparison.
        if (empty($session_tokens)) {
            return;
        }

        if (!is_string($token) || trim($token) === '') {
            throw new Exception('Invalid CSRF token');
        }

        foreach ($session_tokens as $session_token) {
            if (hash_equals((string)$session_token, (string)$token)) {
                return;
            }
        }

        throw new Exception('Invalid CSRF token');
    }

    private function decodeJson($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return array();
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array();
        }

        return $decoded;
    }
}
?>
