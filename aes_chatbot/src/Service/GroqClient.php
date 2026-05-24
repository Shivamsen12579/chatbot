<?php

declare(strict_types=1);

namespace Drupal\aes_chatbot\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Groq chat-completions client.
 *
 * Groq exposes an OpenAI-compatible /chat/completions endpoint, so the
 * request/response shape mirrors OpenAI's.
 */
final class GroqClient {

  private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @throws \Drupal\aes_chatbot\Service\GroqException
   *   On any upstream failure. Message is safe to surface to clients.
   */
  public function chat(string $apiKey, string $model, string $systemPrompt, array $messages, int $maxTokens, float $temperature): string {
    if ($systemPrompt !== '') {
      array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
    }

    try {
      $response = $this->httpClient->request('POST', self::ENDPOINT, [
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => $model,
          'messages' => $messages,
          'max_tokens' => $maxTokens,
          'temperature' => $temperature,
          'stream' => FALSE,
        ],
        'connect_timeout' => 10,
        'timeout' => 60,
      ]);
    }
    catch (ClientException $e) {
      $status = $e->getResponse()?->getStatusCode() ?? 0;
      $body = (string) $e->getResponse()?->getBody();
      $this->logger->warning('Groq 4xx @status: @body', ['@status' => $status, '@body' => $body]);
      throw match ($status) {
        401, 403 => new GroqException('The chatbot is misconfigured (invalid API key).'),
        429 => new GroqException('The chatbot is busy or out of quota. Please try again later.'),
        default => new GroqException('The chatbot could not complete your request.'),
      };
    }
    catch (GuzzleException $e) {
      $this->logger->error('Groq transport error: @msg', ['@msg' => $e->getMessage()]);
      throw new GroqException('The chatbot is temporarily unreachable. Please try again.');
    }

    $body = (string) $response->getBody();
    $data = json_decode($body, TRUE);
    if (!\is_array($data) || empty($data['choices'][0]['message']['content'])) {
      $this->logger->error('Groq returned an unexpected payload: @body', ['@body' => $body]);
      throw new GroqException('The chatbot returned an unexpected response.');
    }

    return trim((string) $data['choices'][0]['message']['content']);
  }

}
