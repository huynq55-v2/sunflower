<?php

namespace Drupal\sunflower_lesson\Plugin\views\field;

use Drupal\views\ResultRow;
use Drupal\views\Plugin\views\field\FieldPluginBase;

/**
 * Provides a Yes/No field indicating if student is in current lesson.
 *
 * @ViewsField("yes_no_lesson_field")
 */
class YesNoLesson extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $account = $values->_entity;
    if (!$account) {
      return '';
    }

    // Lấy Node ID lesson từ URL argument.
    $class_nid = $this->view->args[0] ?? 0;
    if (!$class_nid) {
      return '';
    }

    $class_node = \Drupal\node\Entity\Node::load($class_nid);
    if (!$class_node || !$class_node->hasField('field_student_list')) {
      return '';
    }

    // Cache danh sách học sinh trong lớp.
    static $student_uids = NULL;
    if ($student_uids === NULL) {
      $student_refs = $class_node->get('field_student_list')->referencedEntities();
      $student_uids = array_map(fn($u) => $u->id(), $student_refs);
    }

    // Kiểm tra xem user hiện tại có trong lớp không.
    $in_lesson = in_array($account->id(), $student_uids);
    return $in_lesson ? '✅' : '❌';
  }
}

