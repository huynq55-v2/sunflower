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

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    // $entity chính là user được chọn trong View Bulk Operation
    if ($entity instanceof User && in_array('teacher', $entity->getRoles())) {
      $this->calculateIncomeForTeacher($entity);
    }
  }

  /**
   * Tính thu nhập cho một giáo viên cụ thể.
   */
  protected function calculateIncomeForTeacher(User $teacher) {
    $total_income = 0;
    $teacher_accounts = [];

    // 🔍 Lấy tất cả account thuộc giáo viên này.
    $accounts = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'account',
        'field_student' => $teacher->id(),
      ]);

    foreach ($accounts as $account) {
      $teacher_accounts[] = $account->id();

      // 📊 Lấy danh sách transaction liên quan đến account.
      $transactions = $account->get('field_transactions')->referencedEntities();

      foreach ($transactions as $txn) {
        $bundle = $txn->bundle();
        $amount = (float) $txn->get('field_amount')->value;

        if ($bundle === 'transaction') {
          $txn_type = $txn->get('field_type')->value;

          if ($txn_type === 'credit') {
            $total_income += $amount;
          }
          elseif ($txn_type === 'debit') {
            $total_income -= $amount;
          }
        }
        elseif ($bundle === 'transaction_log') {
          // Giao dịch log: cộng theo giá trị thực (âm nghĩa là trừ).
          $total_income += $amount;
        }
      }
    }

    // 🪙 Tạo node teacher_income mới (KHÔNG xoá cũ)
    $income_node = Node::create([
      'type' => 'teacher_income',
      'title' => 'Thu nhập - ' . $teacher->getDisplayName() . ' (' . date('Y-m-d H:i:s') . ')',
      'field_teacher' => $teacher->id(),
      'field_income' => $total_income,
      'field_teacher_account' => $teacher_accounts,
      'field_received_money' => 0, // chưa lĩnh
    ]);
    $income_node->save();

    \Drupal::messenger()->addStatus(t('Đã tạo bản ghi thu nhập cho giáo viên @name: @amount VND', [
      '@name' => $teacher->getDisplayName(),
      '@amount' => number_format($total_income),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    // Cho phép chạy trong VBO
    return $return_as_object ? AccessResult::allowed() : TRUE;
  }

}
