<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * @Action(
 *   id = "sunflower_toggle_once_boolean",
 *   label = @Translation("Toggle boolean field (only No → Yes)"),
 *   type = "node"
 * )
 */
class ToggleOnceBooleanField extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof NodeInterface) {
      // ⚠️ Đổi field_is_active thành tên field boolean của bạn.
      $field_name = 'field_is_happened';

      if ($entity->hasField($field_name)) {
        $value = $entity->get($field_name)->value;

        if ($value == 0) {
          // Nếu hiện tại = No (0) thì chuyển thành Yes (1).
          $entity->set($field_name, 1);
          $entity->save();
        }
        // Nếu đã Yes (1) thì giữ nguyên, không revert.
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    // Cho phép mọi người có quyền update node.
    return $object->access('update', $account, $return_as_object);
  }

}
