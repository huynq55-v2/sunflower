<?php

namespace Drupal\sunflower_finance\Service;

use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service to manage account balances.
 */
class AccountBalanceManager {

  protected $entityTypeManager;

  /**
   * @var array
   *   A static array to prevent recursion.
   */
  private static $updating = [];

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public function updateRelatedAccounts(Node $entity) {
    $bundle = $entity->bundle();

    switch ($bundle) {
      case 'transaction':
      case 'transaction_log':
        if ($entity->hasField('field_account') && !$entity->get('field_account')->isEmpty()) {
          $target = $entity->get('field_account')->target_id;
          if ($target) {
            $this->updateAccountBalance($target);
          }
        }
        break;

      case 'teacher_income':
        if ($entity->hasField('field_account')) {
          foreach ($entity->get('field_account')->getValue() as $ref) {
            if (!empty($ref['target_id'])) {
              $this->updateAccountBalance($ref['target_id']);
            }
          }
        }
        break;

      case 'account':
        // Khi account thay đổi, tính lại số dư của chính nó.
        // Cơ chế khóa sẽ ngăn chặn vòng lặp ở đây.
        $this->updateAccountBalance($entity->id());
        break;
    }
  }

  public function updateAccountBalance($account_nid) {
    // === ✅ BƯỚC 1: Thêm cơ chế khóa để chống lặp ===
    if (!empty(self::$updating[$account_nid])) {
      return; // Đang xử lý, bỏ qua để tránh lặp vô hạn.
    }

    self::$updating[$account_nid] = TRUE;

    try {
      /** @var \Drupal\node\Entity\Node|null $account */
      $account = $this->entityTypeManager->getStorage('node')->load($account_nid);
      if (!$account || $account->bundle() !== 'account') {
        // Giải phóng khóa trước khi thoát
        self::$updating[$account_nid] = FALSE;
        return;
      }

      $total_balance = 0;
      $transaction_types = ['transaction', 'transaction_log'];

      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $transaction_types, 'IN')
        ->condition('field_account', $account_nid)
        ->accessCheck(FALSE);

      // Chỉ tính các giao dịch CHƯA được chốt sổ.
      $group = $query->orConditionGroup()
        ->notExists('field_has_calculated_money')
        ->condition('field_has_calculated_money', 0);
      $query->condition($group);

      $ids = $query->execute();

      if (!empty($ids)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
        foreach ($nodes as $node) {
          if ($node->hasField('field_amount') && is_numeric($node->get('field_amount')->value)) {
            $amount = (float) $node->get('field_amount')->value;

            // Transaction có credit/debit, transaction_log thì amount có thể âm/dương
            if ($node->bundle() === 'transaction' && $node->hasField('field_type')) {
              $type_value = $node->get('field_type')->value;
              if ($type_value === 'debit') {
                $amount = -$amount;
              }
            }
            $total_balance += $amount;
          }
        }
      }

      if ($account->hasField('field_balance')) {
        $old_balance = (float) ($account->get('field_balance')->value ?? 0.0);

        // === ✅ BƯỚC 2: Chỉ lưu nếu số dư thực sự thay đổi ===
        if ($old_balance !== $total_balance) {
          $account->set('field_balance', $total_balance);
          $account->save(); // Lệnh save này sẽ không gây lặp nữa nhờ cơ chế khóa.

          \Drupal::logger('sunflower_finance')->notice(
            'Cập nhật số dư cho tài khoản @title: @old → @new',
            [
              '@title' => $account->label(),
              '@old' => $old_balance,
              '@new' => $total_balance,
            ]
          );
        }
      }
    }
    finally {
      // === ✅ BƯỚC 3: Luôn giải phóng khóa sau khi chạy xong ===
      self::$updating[$account_nid] = FALSE;
    }
  }
}