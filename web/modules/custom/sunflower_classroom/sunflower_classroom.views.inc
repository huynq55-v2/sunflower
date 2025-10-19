<?php

/**
 * Implements hook_views_data_alter().
 */
function sunflower_classroom_views_data_alter(array &$data) {
  $data['users_field_data']['yes_no_class'] = [
    'title' => t('Yes/No in class'),
    'help' => t('Show Yes if student is in the current class.'),
    'real field' => 'uid', // 👈 chỉ định field thực tế để Views không cố SELECT rỗng
    'field' => [
      'id' => 'yes_no_class_field', // plugin id
    ],
  ];
}
