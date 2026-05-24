<?php

declare(strict_types=1);

namespace Drupal\aes_chatbot\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Request a demo" lead-capture form.
 *
 * Three fields — Full name, Work email, Company — and on submit we trigger
 * two emails via Drupal's MailManager:
 *   1. Confirmation to the visitor.
 *   2. Notification to the site admin (the site default email).
 *
 * No DB storage yet — submissions are emails-only. The hook_mail
 * implementations live in aes_chatbot.module.
 *
 * Note: FormBase already provides $this->configFactory() and
 * $this->getLogger() — we do NOT promote our own properties for those
 * since the parent class declares $configFactory and would collide.
 */
final class RequestDemoForm extends FormBase {

  public function __construct(
    private readonly MailManagerInterface $mailManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.mail'),
    );
  }

  public function getFormId(): string {
    return 'aes_chatbot_request_demo';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'aes-request-demo-form';

    $form['intro'] = [
      '#markup' => '<p>' . $this->t("Tell us a bit about you and we'll be in touch shortly.") . '</p>',
    ];

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full name'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Work email'),
      '#required' => TRUE,
    ];

    $form['company'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company name'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Request demo'),
        '#button_type' => 'primary',
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) $form_state->getValue('name'));
    $email = trim((string) $form_state->getValue('email'));
    $company = trim((string) $form_state->getValue('company'));
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $siteMail = (string) $this->configFactory()->get('system.site')->get('mail');
    $logger = $this->getLogger('aes_chatbot');

    $params = [
      'name' => $name,
      'email' => $email,
      'company' => $company,
    ];

    // 1. Confirmation to the submitter.
    $this->mailManager->mail(
      'aes_chatbot',
      'request_demo_confirmation',
      $email,
      $langcode,
      $params,
      NULL,
      TRUE,
    );

    // 2. Notification to the site admin (if a site mail is configured).
    //    The $reply parameter ($email) becomes the Reply-To header via
    //    MailManager — do NOT also set Reply-To inside hook_mail or
    //    Symfony Mime throws on duplicate headers.
    if ($siteMail !== '') {
      $this->mailManager->mail(
        'aes_chatbot',
        'request_demo_admin_notification',
        $siteMail,
        $langcode,
        $params + ['reply_to' => $email],
        $email,
        TRUE,
      );
    }
    else {
      $logger->warning('Demo request received but system.site.mail is empty — admin notification skipped.');
    }

    $this->messenger()->addStatus($this->t("Thanks @name — we've emailed you a confirmation and a team member will follow up shortly.", ['@name' => $name]));
    $form_state->setRedirectUrl(Url::fromRoute('<front>'));

    $logger->info('Demo request: @name <@email> from @company', [
      '@name' => $name,
      '@email' => $email,
      '@company' => $company,
    ]);
  }

}
