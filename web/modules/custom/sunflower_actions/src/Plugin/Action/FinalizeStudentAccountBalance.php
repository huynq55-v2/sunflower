<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;

/**
 * @Action(
 *   id = "finalize_student_account_balance",
 *   label = @Translation("Chốt sổ tài khoản học sinh"),
 *   type = "node"
 * )
 */
class FinalizeStudentAccountBalance extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof Node && $entity->bundle() === 'account') {
      $this->finalizeBalance($entity);
    }
  }

  /**
   * Tính và chốt sổ cho 1 tài khoản học sinh.
   */
  protected function finalizeBalance(Node $account) {
    $total_balance = 0;
    $related_transactions = [];

    // ✅ Lấy tất cả giao dịch của tài khoản.
    $transactions = $account->get('field_transactions')->referencedEntities();

    foreach ($transactions as $txn) {
      $bundle = $txn->bundle();
      $amount = (int) $txn->get('field_amount')->value;

      // Bỏ qua giao dịch đã được chốt sổ.
      if ($txn->hasField('field_has_calculated_money') && $txn->get('field_has_calculated_money')->value) {
        continue;
      }

      // Chỉ tính transaction hoặc transaction_log.
      if ($bundle === 'transaction') {
        $txn_type = $txn->get('field_type')->value;
        if ($txn_type === 'credit') {
          $total_balance += $amount;
          $related_transactions[] = ['target_id' => $txn->id()];
        }
        elseif ($txn_type === 'debit') {
          $total_balance -= $amount;
          $related_transactions[] = ['target_id' => $txn->id()];
        }
      }
      elseif ($bundle === 'transaction_log') {
        $total_balance += $amount;
        $related_transactions[] = ['target_id' => $txn->id()];
      }
    }

    // ⚠️ Không có giao dịch mới.
    if (empty($related_transactions)) {
      \Drupal::messenger()->addWarning(t('👀 Không có giao dịch mới nào cần chốt sổ cho tài khoản @title.', [
        '@title' => $account->label(),
      ]));
      return;
    }

    // ⚠️ Số dư âm → không chốt sổ.
    if ($total_balance < 0) {
      \Drupal::messenger()->addWarning(t('💤 Tài khoản @title có số dư âm (@amount), không thể chốt sổ.', [
        '@title' => $account->label(),
        '@amount' => $total_balance,
      ]));
      return;
    }

    // ✅ Tạo node chốt sổ học sinh.
    $student_node = Node::create([
      'type' => 'teacher_income', // Hoặc 'student_income' tùy schema của bạn.
      'title' => 'Chốt sổ - ' 
        . $account->label()
        . ' (' . date('Y-m-d H:i') . ')',
      'field_account' => $account->id(),
      'field_amount' => -$total_balance,
      'field_related_transactions' => $related_transactions,
      'field_received_money' => 0,
    ]);
    $student_node->save();

    // ✅ Đánh dấu các giao dịch đã được tính.
    foreach ($related_transactions as $ref) {
      $txn = Node::load($ref['target_id']);
      if ($txn && $txn->hasField('field_has_calculated_money')) {
        $txn->set('field_has_calculated_money', TRUE);
        $txn->save();
      }
    }

    // ✅ Thông báo kết quả.
    \Drupal::messenger()->addStatus(t('✅ Đã chốt sổ cho tài khoản @title với số dư @amount (@count giao dịch).', [
      '@title' => $account->label(),
      '@amount' => $total_balance,
      '@count' => count($related_transactions),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $allowed = $account->hasRole('manager') || $account->hasRole('cashier');
    $result = AccessResult::allowedIf($allowed);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
