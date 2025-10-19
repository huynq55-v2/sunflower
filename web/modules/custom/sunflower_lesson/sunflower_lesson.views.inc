<?php

/**
 * Implements hook_views_data_alter().
 */
function sunflower_lesson_views_data_alter(array &$data) {
  $data['users_field_data']['yes_no_lesson'] = [
    'title' => t('Yes/No in lesson'),
    'help' => t('Show Yes if student is in the current lesson.'),
    'real field' => 'uid',
    'field' => [
      'id' => 'yes_no_lesson_field', // plugin id
    ],
  ];
}
