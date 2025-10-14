<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * @Action(
 *   id = "finalize_account_balance",
 *   label = @Translation("Chốt sổ tài khoản giáo viên"),
 *   type = "user"
 * )
 */
class FinalizeAccountBalance extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof User) {
      $this->finalizeBalance($entity);
    }
  }

  /**
   * Tính và chốt sổ cho một user giáo viên.
   */
  protected function finalizeBalance(User $user) {
    $total_balance = 0;
    $user_accounts = [];
    $related_transactions = [];

    $storage = \Drupal::entityTypeManager()->getStorage('node');

    // Lấy tất cả account liên quan đến user (qua field_student).
    $accounts = $storage->loadByProperties([
      'type' => 'account',
      'field_student' => $user->id(),
    ]);

    foreach ($accounts as $account) {
      $user_accounts[] = $account->id();
      $transactions = $account->get('field_transactions')->referencedEntities();

      foreach ($transactions as $txn) {
        $bundle = $txn->bundle();
        $amount = (int) $txn->get('field_amount')->value;

        // Bỏ qua giao dịch đã được chốt sổ (đã tính tiền).
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
    }

    // Nếu không có giao dịch mới → bỏ qua.
    if (empty($related_transactions)) {
      \Drupal::messenger()->addWarning(t('👀 Không có giao dịch mới nào cần chốt sổ cho @name.', [
        '@name' => $user->getDisplayName(),
      ]));
      return;
    }

    // Nếu tổng <= 0 → không tạo node mới.
    if ($total_balance <= 0) {
      \Drupal::messenger()->addWarning(t('💤 Số dư của @name không đủ để chốt sổ (số dư: @amount).', [
        '@name' => $user->getDisplayName(),
        '@amount' => $total_balance,
      ]));
      return;
    }

    // ✅ Tạo node teacher_income (dùng chung cho cả teacher và student).
    $income_node = Node::create([
      'type' => 'teacher_income',
      'title' => 'Chốt sổ - ' 
  . $user->getDisplayName()
  . ' - ' 
  . ($user->hasField('field_full_name') && !$user->get('field_full_name')->isEmpty() ? $user->get('field_full_name')->value : '')
  . ' (' . date('Y-m-d H:i') . ')',
      'field_teacher' => $user->id(),
      'field_amount' => -$total_balance,
      'field_account' => $user_accounts,
      'field_received_money' => 0,
      'field_related_transactions' => $related_transactions,
    ]);
    $income_node->save();

    // ✅ Đánh dấu các giao dịch này là đã tính.
    foreach ($related_transactions as $ref) {
      $txn = Node::load($ref['target_id']);
      if ($txn && $txn->hasField('field_has_calculated_money')) {
        $txn->set('field_has_calculated_money', TRUE);
        $txn->save();
      }
    }

    // ✅ Thông báo kết quả.
    \Drupal::messenger()->addStatus(t('✅ Đã chốt sổ với số dư @amount cho người dùng @name (@count giao dịch).', [
      '@amount' => $total_balance,
      '@name' => $user->getDisplayName(),
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
