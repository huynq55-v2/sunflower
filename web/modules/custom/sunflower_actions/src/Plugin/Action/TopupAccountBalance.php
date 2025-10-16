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

  public function defaultConfiguration() {
    return [
      'amount' => 0,
      'reason' => '',
    ];
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount'),
      '#description' => $this->t('Enter a positive number to top up or negative to deduct.'),
      '#required' => TRUE,
      '#step' => 1,
    ];

    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reason'),
      '#description' => $this->t('Describe the purpose of this transaction.'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['amount'] = $form_state->getValue('amount');
    $this->configuration['reason'] = $form_state->getValue('reason');
  }

  public function execute($entity = NULL) {
    if (!$entity instanceof Node || $entity->bundle() !== 'account') {
      return;
    }

    $amount = (float) $this->configuration['amount'];
    $reason = trim($this->configuration['reason']);
    $cashier = \Drupal::currentUser();

    $now = \Drupal::time()->getRequestTime();
    $field_date_value = \Drupal::service('date.formatter')
      ->format($now, 'custom', 'Y-m-d\TH:i:s');

    // 1️⃣ Tạo node transaction_log.
    $txn_log = Node::create([
      'type' => 'transaction_log',
      'title' => sprintf(
        '%s for %s%s (%+.0f)',
        ($amount >= 0 ? 'Topup' : 'Deduct'),
        $entity->label(),
        ($entity->hasField('field_full_name') && !$entity->get('field_full_name')->isEmpty())
          ? ' (' . $entity->get('field_full_name')->value . ')'
          : '',
        $amount
      ),
      'field_account' => ['target_id' => $entity->id()],
      'field_amount' => $amount,
      'field_cashier' => ['target_id' => $cashier->id()],
      'field_date' => $field_date_value,
      'field_topup_reason' => $reason,
    ]);

    $txn_log->setCreatedTime(strtotime($field_date_value) ?: $now);
    $txn_log->save(); // <- Hook `hook_entity_insert` sẽ được kích hoạt ở đây và tự động gọi service tính toán lại số dư.

    // 2️⃣ Cập nhật lại danh sách field_transactions (nếu bạn vẫn cần trường này).
    // Lưu ý: hàm `sunflower_update_account_transactions` cũng có `save()`.
    // Điều này cũng sẽ kích hoạt `hook_entity_update` và service sẽ chạy,
    // nhưng cơ chế khóa đã ngăn chặn lặp.
    if (function_exists('sunflower_update_account_transactions')) {
      sunflower_update_account_transactions($entity, $txn_log);
    }
    
    // 3️⃣ KHÔNG cần gọi hàm tính toán lại ở đây nữa.
    // if (function_exists('sunflower_recalculate_account_balance')) {
    //   sunflower_recalculate_account_balance($entity);
    // }

    // 4️⃣ Hiển thị thông báo.
    \Drupal::messenger()->addStatus(t('✅ Transaction of %amount has been applied to %account.', [
      '%amount' => sprintf('%+.0f', $amount),
      '%account' => $entity->label(),
    ]));
  }

  /**
   * Xác định timestamp hiệu lực của 1 giao dịch.
   */
  private static function getEffectiveTimestamp(Node $txn): int {
    // Transaction: dựa vào field_lesson → field_study_time hoặc field_date.
    if ($txn->bundle() === 'transaction') {
      if ($txn->hasField('field_lesson') && !$txn->get('field_lesson')->isEmpty()) {
        $lesson = $txn->get('field_lesson')->entity;
        if ($lesson && $lesson->hasField('field_study_time') && !$lesson->get('field_study_time')->isEmpty()) {
          return strtotime($lesson->get('field_study_time')->value . ' 00:00:00');
        }
      }
      if ($txn->hasField('field_date') && !$txn->get('field_date')->isEmpty()) {
        return strtotime($txn->get('field_date')->value);
      }
    }

    // Transaction_log: dùng field_date.
    if ($txn->bundle() === 'transaction_log' && $txn->hasField('field_date') && !$txn->get('field_date')->isEmpty()) {
      return strtotime($txn->get('field_date')->value);
    }

    // Fallback: thời gian tạo node.
    return $txn->getCreatedTime();
  }

  public function access($object, $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $allowed = $account->hasRole('cashier') || $account->hasRole('manager') || $account->hasRole('administrator');
    return $return_as_object ? AccessResult::allowedIf($allowed) : $allowed;
  }
}
