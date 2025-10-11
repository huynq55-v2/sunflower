<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * @Action(
 *   id = "calculate_teacher_income",
 *   label = @Translation("Tính thu nhập cho giáo viên"),
 *   type = "user"
 * )
 */
class CalculateTeacherIncome extends ActionBase {

  public function execute($entity = NULL) {
    if ($entity instanceof User && $entity->hasRole('teacher')) {
      $this->calculateIncomeForTeacher($entity);
    }
  }

  protected function calculateIncomeForTeacher(User $teacher) {
    $total_income = 0;
    $teacher_accounts = [];
    $related_transactions = [];

    $storage = \Drupal::entityTypeManager()->getStorage('node');

    // Lấy tất cả account của giáo viên.
    $accounts = $storage->loadByProperties([
      'type' => 'account',
      'field_student' => $teacher->id(),
    ]);

    foreach ($accounts as $account) {
      $teacher_accounts[] = $account->id();
      $transactions = $account->get('field_transactions')->referencedEntities();

      foreach ($transactions as $txn) {
        $bundle = $txn->bundle();
        $amount = (int) $txn->get('field_amount')->value;

        // ✅ Bỏ qua nếu đã đánh dấu đã trả lương
        if ($txn->hasField('field_has_calculated_money')) {
          $paid = (bool) $txn->get('field_has_calculated_money')->value;
          if ($paid) {
            continue;
          }
        }

        // Chỉ xử lý transaction hoặc transaction_log
        if ($bundle === 'transaction') {
          $txn_type = $txn->get('field_type')->value;
          if ($txn_type === 'credit') {
            $total_income += $amount;
            $related_transactions[] = ['target_id' => $txn->id()];
          }
          elseif ($txn_type === 'debit') {
            $total_income -= $amount;
            $related_transactions[] = ['target_id' => $txn->id()];
          }
        }
        elseif ($bundle === 'transaction_log') {
          $total_income += $amount;
          $related_transactions[] = ['target_id' => $txn->id()];
        }
      }
    }

    if (empty($related_transactions)) {
      \Drupal::messenger()->addWarning(t('👀 Không có giao dịch mới nào cần tính cho giáo viên @name.', [
        '@name' => $teacher->getDisplayName(),
      ]));
      return;
    }

    // Tạo node teacher_income.
    $income_node = Node::create([
      'type' => 'teacher_income',
      'title' => 'Thu nhập - ' . $teacher->getDisplayName() . ' (' . date('Y-m-d H:i') . ')',
      'field_teacher' => $teacher->id(),
      'field_amount' => -$total_income,
      'field_account' => $teacher_accounts,
      'field_received_money' => 0,
      'field_related_transactions' => $related_transactions,
    ]);
    $income_node->save();

    // ✅ Đánh dấu các giao dịch này là đã trả cho giáo viên.
    foreach ($related_transactions as $ref) {
      $txn = Node::load($ref['target_id']);
      if ($txn && $txn->hasField('field_has_calculated_money')) {
        $txn->set('field_has_calculated_money', TRUE);
        $txn->save();
      }
    }

    foreach ($teacher_accounts as $account_id) {
      $account = Node::load($account_id);

      foreach ($account->get('field_transactions')->referencedEntities() as $txn) {
        // Chỉ cộng các giao dịch chưa trả lương
        if ($txn->hasField('field_has_calculated_money') && (bool) $txn->get('field_has_calculated_money')->value === TRUE) {
          continue;
        }

        $bundle = $txn->bundle();
        $amount = (int) $txn->get('field_amount')->value;

      }

      $account->save();
    }

    // Thông báo
    \Drupal::messenger()->addStatus(t('✅ Đã tính thu nhập @amount cho giáo viên @teacher (@count giao dịch).', [
      '@amount' => $total_income,
      '@teacher' => $teacher->getDisplayName(),
      '@count' => count($related_transactions),
    ]));
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $allowed = $account->hasRole('manager') || $account->hasRole('cashier');
    $result = AccessResult::allowedIf($allowed);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
