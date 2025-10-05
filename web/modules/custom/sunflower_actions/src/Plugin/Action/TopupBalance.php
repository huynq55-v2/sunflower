<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Access\AccessResult;

/**
 * @Action(
 *   id = "sunflower_topup_balance",
 *   label = @Translation("Top up or deduct account balance for student or teacher"),
 *   type = "user"
 * )
 */
class TopupBalance extends ConfigurableActionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'amount' => 0,
      'reason' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount to add (use negative number to deduct)'),
      '#description' => $this->t('Enter a positive number to top up, or negative to deduct money from the student account.'),
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reason'),
      '#description' => $this->t('Explain why this transaction is made.'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['amount'] = $form_state->getValue('amount');
    $this->configuration['reason'] = $form_state->getValue('reason');
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof UserInterface && $entity->hasField('field_account_balance')) {
      $amount = (float) $this->configuration['amount'];
      $reason = trim($this->configuration['reason']);
      $current = (float) $entity->get('field_account_balance')->value;
      $new_balance = $current + $amount;

      $entity->set('field_account_balance', $new_balance);
      $entity->save();

      // ✅ Ghi log vào content type Transaction log.
      $cashier = \Drupal::currentUser();
      $field_date_value = \Drupal::service('date.formatter')
        ->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d\TH:i:s');

      $node = \Drupal\node\Entity\Node::create([
        'type' => 'transaction_log',
        'title' => sprintf('Topup for %s (%+.0f)', $entity->getAccountName(), $amount),
        'field_student' => ['target_id' => $entity->id()],
        'field_amount' => $amount,
        'field_cashier' => ['target_id' => $cashier->id()],
        'field_date' => $field_date_value,
        'field_topup_reason' => $reason,
      ]);
      $node->save();

      \Drupal::messenger()->addStatus(t('✅ Updated balance for %name by %amount (new balance: %new).', [
        '%name' => $entity->getAccountName(),
        '%amount' => $amount,
        '%new' => $new_balance,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();

    if ($account->hasRole('cashier') || $account->hasRole('manager')) {
      return $return_as_object ? AccessResult::allowed() : TRUE;
    }

    return $return_as_object ? AccessResult::forbidden() : FALSE;
  }

}
