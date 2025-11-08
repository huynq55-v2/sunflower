<?php

namespace Drupal\sunflower_classroom\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * @Action(
 *   id = "sunflower_create_lesson_from_classroom",
 *   label = @Translation("Tạo buổi học mới từ lớp học"),
 *   type = "node"
 * )
 */
class CreateLessonFromClassroom extends ConfigurableActionBase {

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    // Chỉ áp dụng cho content type classroom.
    $allowed = $object->bundle() === 'classroom';
    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden();
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['study_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Ngày học'),
      '#default_value' => date('Y-m-d'),
      '#required' => TRUE,
    ];
    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $date_string = $form_state->getValue('study_date') ?: date('Y-m-d');
    $this->configuration['study_time'] = $date_string;
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities) {
    $study_time = $this->configuration['study_time'];

    foreach ($entities as $classroom) {
      if ($classroom->bundle() !== 'classroom') {
        continue;
      }

      // Lấy thông tin lớp học.
      $classroom_id = $classroom->id();
      $classroom_title = $classroom->getTitle();

      // Lấy danh sách học sinh từ field_student_list.
      $student_refs = $classroom->get('field_student_list')->getValue();
      $student_ids = array_column($student_refs, 'target_id');

      // Xác định số thứ tự buổi học (đếm các lesson hiện có).
      $existing_lesson_count = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'lesson')
        ->condition('field_classroom.target_id', $classroom_id)
        ->count()
        ->execute();
      $next_index = $existing_lesson_count + 1;
      $lesson_number = str_pad($next_index, 3, '0', STR_PAD_LEFT);

      // Tạo node Lesson mới.
      $lesson = Node::create([
        'type' => 'lesson',
        'title' => "{$classroom_title} buổi {$lesson_number}",
        'field_classroom' => ['target_id' => $classroom_id],
        'field_is_happened' => 1,
        'field_student_list' => array_map(fn($id) => ['target_id' => $id], $student_ids),
        'field_study_time' => $study_time,
        'status' => 1,
      ]);
      $lesson->save();
    }

    \Drupal::messenger()->addMessage($this->t('Đã tạo buổi học mới cho @count lớp học.', ['@count' => count($entities)]));
  }

  public function execute($entity = NULL) {
    // Gọi lại executeMultiple cho nhất quán.
    if ($entity) {
      $this->executeMultiple([$entity]);
    }
  }

}
