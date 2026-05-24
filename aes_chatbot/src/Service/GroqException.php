<?php

declare(strict_types=1);

namespace Drupal\aes_chatbot\Service;

/**
 * Thrown by GroqClient when an upstream call fails.
 *
 * The message is always sanitized for client display.
 */
final class GroqException extends \RuntimeException {
}
