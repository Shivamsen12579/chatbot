<?php

declare(strict_types=1);

namespace Drupal\aes_chatbot\Controller;

use Drupal\aes_chatbot\Service\GroqClient;
use Drupal\aes_chatbot\Service\GroqException;
use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * AJAX endpoint for the chatbot widget.
 */
final class ChatbotController extends ControllerBase {

  /**
   * Hard caps to bound abuse and cost.
   *
   * - MAX_MESSAGES: trim chat history before forwarding to the LLM so
   *   long conversations don't quietly inflate token usage.
   * - MAX_USER_LEN: truncate user messages so a paste-bomb can't blow
   *   past the model's context window or our bill.
   * - FLOOD_*: 30 requests per hour per (IP, block). Primary defense on
   *   the anonymous path, since `_csrf_request_header_token` is bypassed
   *   there (see aes_chatbot.routing.yml).
   */
  private const MAX_MESSAGES = 30;
  private const MAX_USER_LEN = 2000;
  private const FLOOD_THRESHOLD = 30;
  private const FLOOD_WINDOW = 3600;

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly GroqClient $groq,
    private readonly FloodInterface $flood,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('aes_chatbot.groq_client'),
      $container->get('flood'),
    );
  }

  public function message(Request $request, string $block_uuid): JsonResponse {
    $flood_id = 'aes_chatbot:' . $block_uuid;
    if (!$this->flood->isAllowed($flood_id, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW)) {
      throw new TooManyRequestsHttpException(60, 'You have sent too many messages. Please slow down.');
    }
    $this->flood->register($flood_id, self::FLOOD_WINDOW);

    $payload = json_decode($request->getContent(), TRUE);
    if (!\is_array($payload) || !isset($payload['messages']) || !\is_array($payload['messages'])) {
      throw new BadRequestHttpException('Invalid request payload.');
    }
    $messages = $this->sanitizeMessages($payload['messages']);
    if (!$messages) {
      throw new BadRequestHttpException('No valid messages.');
    }

    $block = $this->entityRepository->loadEntityByUuid('block_content', $block_uuid);
    if (!$block instanceof BlockContentInterface || $block->bundle() !== 'aes_chatbot') {
      throw new NotFoundHttpException('Unknown chatbot.');
    }
    if (!$block->isPublished()) {
      throw new AccessDeniedHttpException('This chatbot is disabled.');
    }

    $apiKey = $this->fieldString($block, 'field_aes_chatbot_api_key');
    $model = $this->fieldString($block, 'field_aes_chatbot_model') ?: 'llama-3.1-8b-instant';
    $systemPrompt = $this->fieldString($block, 'field_aes_chatbot_system_prompt');
    $maxTokens = (int) ($this->fieldString($block, 'field_aes_chatbot_max_tokens') ?: 500);
    $temperature = (float) ($this->fieldString($block, 'field_aes_chatbot_temperature') ?: 0.7);

    if ($apiKey === '') {
      $this->getLogger('aes_chatbot')->error('Chatbot block @uuid has no API key.', ['@uuid' => $block_uuid]);
      return new JsonResponse(['error' => 'This chatbot is not configured yet.'], 500);
    }

    try {
      $reply = $this->groq->chat($apiKey, $model, $systemPrompt, $messages, $maxTokens, $temperature);
    }
    catch (GroqException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 502);
    }

    return new JsonResponse(['reply' => $reply]);
  }

  /**
   * Validate + trim the chat history before forwarding to the LLM.
   *
   * Allowed roles: 'user' and 'assistant' only. System messages are
   * pulled from the block field by chat() — we never trust the client to
   * inject them, which would let any visitor override the system prompt.
   *
   * The final invariant — last message must be from 'user' — exists
   * because OpenAI-compatible APIs (Groq included) reject requests
   * whose last message has role=assistant. Better to 400 here than to
   * forward a doomed request upstream.
   */
  private function sanitizeMessages(array $raw): array {
    $clean = [];
    foreach ($raw as $msg) {
      if (!\is_array($msg) || !isset($msg['role'], $msg['content'])) {
        continue;
      }
      $role = \is_string($msg['role']) ? $msg['role'] : '';
      $content = \is_string($msg['content']) ? $msg['content'] : '';
      if (!\in_array($role, ['user', 'assistant'], TRUE) || $content === '') {
        continue;
      }
      if ($role === 'user' && mb_strlen($content) > self::MAX_USER_LEN) {
        $content = mb_substr($content, 0, self::MAX_USER_LEN);
      }
      $clean[] = ['role' => $role, 'content' => $content];
    }
    if (\count($clean) > self::MAX_MESSAGES) {
      $clean = \array_slice($clean, -self::MAX_MESSAGES);
    }
    $last = end($clean);
    if (!$last || $last['role'] !== 'user') {
      return [];
    }
    return $clean;
  }

  private function fieldString(BlockContentInterface $block, string $field): string {
    if (!$block->hasField($field) || $block->get($field)->isEmpty()) {
      return '';
    }
    return trim((string) $block->get($field)->value);
  }

}
