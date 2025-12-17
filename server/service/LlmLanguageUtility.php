<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Language Utility Class
 *
 * Shared utilities for internationalization and language-specific operations
 * across LLM services. Contains common language processing functions to
 * eliminate code duplication.
 */
class LlmLanguageUtility
{
    /**
     * Get language-specific confirmation prompts
     *
     * @param string $language Language code (en, de, fr, es, it, etc.)
     * @return array Prompts array with 'question', 'yes', 'partial', 'no' keys
     */
    public static function getConfirmationPrompts($language)
    {
        $prompts = [
            'en' => [
                'question' => 'Do you feel you understand this topic well enough to continue?',
                'yes' => 'Yes, I understand this topic',
                'partial' => 'I need more explanation',
                'no' => 'Please explain again from the beginning'
            ],
            'de' => [
                'question' => 'Hast du das Gefühl, dass du dieses Thema gut genug verstehst, um fortzufahren?',
                'yes' => 'Ja, ich verstehe dieses Thema',
                'partial' => 'Ich brauche mehr Erklärung',
                'no' => 'Bitte erkläre es noch einmal von Anfang an'
            ],
            'fr' => [
                'question' => 'Pensez-vous comprendre suffisamment ce sujet pour continuer?',
                'yes' => 'Oui, je comprends ce sujet',
                'partial' => 'J\'ai besoin de plus d\'explications',
                'no' => 'Veuillez expliquer à nouveau depuis le début'
            ],
            'es' => [
                'question' => '¿Sientes que entiendes este tema lo suficiente para continuar?',
                'yes' => 'Sí, entiendo este tema',
                'partial' => 'Necesito más explicación',
                'no' => 'Por favor explica de nuevo desde el principio'
            ],
            'it' => [
                'question' => 'Senti di capire abbastanza questo argomento per continuare?',
                'yes' => 'Sì, capisco questo argomento',
                'partial' => 'Ho bisogno di più spiegazioni',
                'no' => 'Per favore spiega di nuovo dall\'inizio'
            ],
            'pt' => [
                'question' => 'Você sente que entende este tópico o suficiente para continuar?',
                'yes' => 'Sim, eu entendo este tópico',
                'partial' => 'Preciso de mais explicação',
                'no' => 'Por favor, explique novamente desde o início'
            ],
            'nl' => [
                'question' => 'Heb je het gevoel dat je dit onderwerp goed genoeg begrijpt om door te gaan?',
                'yes' => 'Ja, ik begrijp dit onderwerp',
                'partial' => 'Ik heb meer uitleg nodig',
                'no' => 'Leg het alsjeblieft opnieuw uit vanaf het begin'
            ]
        ];

        return $prompts[$language] ?? $prompts['en'];
    }
}
?>
