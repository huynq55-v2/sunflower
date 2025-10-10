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
    // Chỉ chạy cho node account.
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
      'title' => sprintf('%s for %s (%+.0f)', ($amount >= 0 ? 'Topup' : 'Deduct'), $entity->label(), $amount),
      'field_account' => ['target_id' => $entity->id()],
      'field_amount' => $amount,
      'field_cashier' => ['target_id' => $cashier->id()],
      'field_date' => $field_date_value,
      'field_topup_reason' => $reason,
    ]);

    $txn_log->setCreatedTime(strtotime($field_date_value) ?: $now);
    $txn_log->save();

    // 2️⃣ Cập nhật lại danh sách field_transactions theo đúng thứ tự thời gian.
    if ($entity->hasField('field_transactions')) {
      $existing_refs = $entity->get('field_transactions')->referencedEntities();

      // Thêm transaction_log mới vào danh sách, sau đó sắp xếp theo thời gian hiệu lực.
      $existing_refs[] = $txn_log;

      usort($existing_refs, function ($a, $b) {
        // Tính timestamp hiệu lực của mỗi transaction.
        $time_a = self::getEffectiveTimestamp($a);
        $time_b = self::getEffectiveTimestamp($b);
        if ($time_a === $time_b) {
          return $a->id() <=> $b->id();
        }
        return $time_a <=> $time_b;
      });

      $new_refs = [];
      foreach ($existing_refs as $ref) {
        $new_refs[] = ['target_id' => $ref->id()];
      }

      $entity->set('field_transactions', $new_refs);
      $entity->save();
    }

    // 3️⃣ Gọi hàm tính lại toàn bộ lịch sử giao dịch.
    if (function_exists('sunflower_recalculate_account_balance')) {
      sunflower_recalculate_account_balance($entity);
    }

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
    $allowed = $account->hasRole('cashier') || $account->hasRole('manager');
    return $return_as_object ? AccessResult::allowedIf($allowed) : $allowed;
  }
}
