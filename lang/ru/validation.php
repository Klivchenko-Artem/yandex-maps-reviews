<?php

// Только правила, которые реально используются в приложении: пользователь не должен
// видеть «The логин field must be a valid email address.».
return [
    'boolean' => 'Поле «:attribute» должно быть логическим значением.',
    'email' => 'Поле «:attribute» должно быть корректным email.',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'max' => [
        'numeric' => 'Поле «:attribute» не может быть больше :max.',
        'string' => 'Поле «:attribute» не может быть длиннее :max символов.',
        'array' => 'Поле «:attribute» не может содержать больше :max элементов.',
        'file' => 'Файл «:attribute» не может быть больше :max КБ.',
    ],
    'min' => [
        'numeric' => 'Поле «:attribute» должно быть не меньше :min.',
        'string' => 'Поле «:attribute» должно быть не короче :min символов.',
        'array' => 'Поле «:attribute» должно содержать не меньше :min элементов.',
        'file' => 'Файл «:attribute» должен быть не меньше :min КБ.',
    ],
    'required' => 'Заполните поле «:attribute».',
    'string' => 'Поле «:attribute» должно быть строкой.',
    'url' => 'Поле «:attribute» должно быть ссылкой.',

    'attributes' => [
        'page' => 'номер страницы',
    ],
];
