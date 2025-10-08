<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;

/**
 * @Action(
 *   id = "sunflower_topup_account_balance",
 *   label = @Translation("Top up or deduct account balance (Account node)"),
 *   type = "node"
 * )
 */
class TopupAccountBalance extends ConfigurableActionBase {

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
      '#description' => $this->t('Enter a positive number to top up, or negative to deduct from the account.'),
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
    // Chỉ xử lý nếu là node Account.
    if (!$entity instanceof Node || $entity->bundle() !== 'account') {
      return;
    }

    $amount = (float) $this->configuration['amount'];
    $reason = trim($this->configuration['reason']);
    $cashier = \Drupal::currentUser();

    // Cập nhật số dư tài khoản.
    $current = (float) $entity->get('field_balance')->value;
    $new_balance = $current + $amount;
    $entity->set('field_balance', $new_balance);
    $entity->save();

    // Lấy ngày hiện tại.
    $field_date_value = \Drupal::service('date.formatter')
      ->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d\TH:i:s');

    // 🧾 Ghi log giao dịch.
    $log = Node::create([
      'type' => 'transaction_log',
      'title' => sprintf('Topup for %s (%+.0f)', $entity->label(), $amount),
      'field_account' => ['target_id' => $entity->id()],
      'field_amount' => $amount,
      'field_cashier' => ['target_id' => $cashier->id()],
      'field_date' => $field_date_value,
      'field_topup_reason' => $reason,
    ]);
    $log->save();

    // Nếu account có field_transactions → thêm liên kết log này vào.
    if ($entity->hasField('field_transactions')) {
      $transactions = $entity->get('field_transactions')->getValue();
      $transactions[] = ['target_id' => $log->id()];
      $entity->set('field_transactions', $transactions);
      $entity->save();
    }

    // Hiển thị thông báo.
    \Drupal::messenger()->addStatus(t('✅ Updated balance for %account by %amount (new balance: %new).', [
      '%account' => $entity->label(),
      '%amount' => $amount,
      '%new' => $new_balance,
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $allowed = $account->hasRole('cashier') || $account->hasRole('manager');
    return $return_as_object ? AccessResult::allowedIf($allowed) : $allowed;
  }

}
