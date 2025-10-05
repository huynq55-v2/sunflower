<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * @Action(
 *   id = "sunflower_toggle_boolean",
 *   label = @Translation("Toggle boolean field (Yes ↔ No)"),
 *   type = "node"
 * )
 */
class ToggleBooleanField extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof NodeInterface) {
      // ⚠️ Thay bằng tên field boolean của bạn.
      $field_name = 'field_is_happened';

      if ($entity->hasField($field_name)) {
        $current = (int) $entity->get($field_name)->value;
        $new_value = $current ? 0 : 1;

        $entity->set($field_name, $new_value);
        $entity->save();

        \Drupal::messenger()->addMessage(t('Đã chuyển "@title" từ @old → @new.', [
          '@title' => $entity->label(),
          '@old' => $current ? 'Có học' : 'Chưa học',
          '@new' => $new_value ? 'Có học' : 'Chưa học',
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    // Chỉ cho phép nếu user có quyền cập nhật node.
    return $object->access('update', $account, $return_as_object);
  }

}
