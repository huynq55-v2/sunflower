<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Messenger\MessengerTrait;

/**
 * @Action(
 *   id = "sunflower_delete_lesson_if_not_happened",
 *   label = @Translation("Xóa buổi học (chỉ khi chưa diễn ra)"),
 *   type = "node"
 * )
 */
class DeleteLessonIfNotHappened extends ActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof NodeInterface) {
      $field_name = 'field_is_happened';

      // Kiểm tra nếu node có field và là content type 'lesson'.
      if ($entity->bundle() === 'lesson' && $entity->hasField($field_name)) {
        $is_happened = (bool) $entity->get($field_name)->value;

        if ($is_happened) {
          // Nếu buổi học đã diễn ra → không cho xóa.
          $this->messenger()->addError(t('Không thể xóa buổi học "@title" vì đã diễn ra.', [
            '@title' => $entity->label(),
          ]));
        }
        else {
          // Nếu chưa diễn ra → cho phép xóa.
          $entity->delete();
          $this->messenger()->addStatus(t('Đã xóa buổi học "@title".', [
            '@title' => $entity->label(),
          ]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    // Cho phép hiện hành động này nếu user có quyền xóa node.
    $access = $object->access('delete', $account, TRUE);
    return $return_as_object ? $access : $access->isAllowed();
  }
}
