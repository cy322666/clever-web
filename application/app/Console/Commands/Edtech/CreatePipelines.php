<?php

namespace App\Console\Commands\Edtech;

use App\Models\Core\Account;
use App\Models\User;
use App\Services\amoCRM\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

class CreatePipelines extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-pipelines {user_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    private array $pipelines = [
        [
            'name' => 'Основная',
            "is_main" => true,
            "sort" => 10,
            'statuses' => [
                [
                    'name' => 'Новый лид',
                    'sort' => 1,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                        [
                            "level" => "master",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                    ],
                ],
                [
                    'name' => 'Взят в работу',
                    'sort' => 2,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                        ],
                    ],
                ],
                [
                    'name' => 'Контакт установлен',
                    'sort' => 3,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Когда получилось связаться с клиентом переводим в этот этап",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Когда получилось связаться с клиентом переводим в этот этап",
                        ],
                        [
                            "level" => "master",
                            "description" => "Когда получилось связаться с клиентом переводим в этот этап",
                        ],
                    ],
                ],
                [
                    'name' => 'Консультация проведена',
                    'sort' => 4,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Те клиенты, которым провели консультацию",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Те клиенты, которым провели консультацию",
                        ],
                        [
                            "level" => "master",
                            "description" => "Те клиенты, которым провели консультацию",
                        ],
                    ],
                ],
                [
                    'name' => 'Счет выставлен',
                    'sort' => 5,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "На этом этапе клиенты, которые оплачивают",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "На этом этапе клиенты, которые оплачивают",
                        ],
                        [
                            "level" => "master",
                            "description" => "На этом этапе клиенты, которые оплачивают",
                        ],
                    ],
                ],
                [
                    'name' => 'Счет оплачен',
                    'sort' => 6,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'Повторные продажи',
            "is_main" => false,
            "sort" => 20,
            'statuses' => [
                [
                    'name' => 'Новый лид',
                    'sort' => 20,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                        [
                            "level" => "master",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                    ],
                ],
                [
                    'name' => 'Остался месяц',
                    'sort' => 30,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "В этом этапе клиенты, у которых до следующей продажи остался месяц",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "В этом этапе клиенты, у которых до следующей продажи остался месяц",
                        ],
                        [
                            "level" => "master",
                            "description" => "В этом этапе клиенты, у которых до следующей продажи остался месяц",
                        ],
                    ],
                ],
                [
                    'name' => 'Осталась неделя',
                    'sort' => 40,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "В этом этапе клиенты, у которых до следующей продажи осталась неделя",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "В этом этапе клиенты, у которых до следующей продажи осталась неделя",
                        ],
                        [
                            "level" => "master",
                            "description" => "В этом этапе клиенты, у которых до следующей продажи осталась неделя",
                        ],
                    ],
                ],
                [
                    'name' => 'Взят в работу',
                    'sort' => 30,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                        ],
                    ],
                ],
                [
                    'name' => 'Предложение сделано',
                    'sort' => 50,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиентам сделали предложение на повторную покупку",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиентам сделали предложение на повторную покупку",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиентам сделали предложение на повторную покупку",
                        ],
                    ],
                ],
                [
                    'name' => 'Счет выставлен',
                    'sort' => 60,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "На этом этапе клиенты, которые оплачивают",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "На этом этапе клиенты, которые оплачивают",
                        ],
                        [
                            "level" => "master",
                            "description" => "На этом этапе клиенты, которые оплачивают",
                        ],
                    ],
                ],
                [
                    'name' => 'Счет оплачен',
                    'sort' => 70,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'Запуск продукта',
            "is_main" => false,
            "sort" => 30,
            'statuses' => [
                [
                    'name' => 'Новый лид',
                    'sort' => 10,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                        [
                            "level" => "master",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                    ],
                ],
                [
                    'name' => 'Участие подтверждено',
                    'sort' => 20,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, которые подтвердили участие",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, которые подтвердили участие",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, которые подтвердили участие",
                        ],
                    ],
                ],
                [
                    'name' => 'Пришел',
                    'sort' => 30,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "На этом этапе клиенты, которые пришли на запуск",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "На этом этапе клиенты, которые пришли на запуск",
                        ],
                        [
                            "level" => "master",
                            "description" => "На этом этапе клиенты, которые пришли на запуск",
                        ],
                    ],
                    [
                        'name' => 'Взят в работу',
                        'sort' => 40,
                        "descriptions" => [
                            [
                                "level" => "newbie",
                                "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                            ],
                            [
                                "level" => "candidate",
                                "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                            ],
                            [
                                "level" => "master",
                                "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                            ],
                        ],
                    ],
                    [
                        'name' => 'Контакт установлен',
                        'sort' => 50,
                        "descriptions" => [
                            [
                                "level" => "newbie",
                                "description" => "Когда получилось связаться с клиентом переводим в этот этап",
                            ],
                            [
                                "level" => "candidate",
                                "description" => "Когда получилось связаться с клиентом переводим в этот этап",
                            ],
                            [
                                "level" => "master",
                                "description" => "Когда получилось связаться с клиентом переводим в этот этап",
                            ],
                        ],
                    ],
                    [
                        'name' => 'Счет выставлен',
                        'sort' => 60,
                        "color" => "#fffeb2",
                        "descriptions" => [
                            [
                                "level" => "newbie",
                                "description" => "На этом этапе клиенты, которые оплачивают",
                            ],
                            [
                                "level" => "candidate",
                                "description" => "На этом этапе клиенты, которые оплачивают",
                            ],
                            [
                                "level" => "master",
                                "description" => "На этом этапе клиенты, которые оплачивают",
                            ],
                        ],
                    ],
                    [
                        'name' => 'Счет оплачен',
                        'sort' => 70,
                        "color" => "#fffeb2",
                        "descriptions" => [
                            [
                                "level" => "newbie",
                                "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                            ],
                            [
                                "level" => "candidate",
                                "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                            ],
                            [
                                "level" => "master",
                                "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                            ],
                        ],
                    ],
                ]
            ]
        ],
        [
            'name' => 'Вебинарная',
            "is_main" => false,
            "sort" => 40,
            'statuses' => [
                [
                    'name' => 'Заполнена анкета',
                    'sort' => 10,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, которые хотят пойти на вебинар",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, которые хотят пойти на вебинар",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, которые хотят пойти на вебинар",
                        ],
                    ],
                ],
                [
                    'name' => 'Участие подтверждено',
                    'sort' => 20,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, подтвердившие участие на вебинаре",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, подтвердившие участие на вебинаре",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, подтвердившие участие на вебинаре",
                        ],
                    ],
                ],
                [
                    'name' => 'Холодные',
                    'sort' => 30,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, входящие в холодный сегмент. Работаем с ними после разбора теплых",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, входящие в холодный сегмент. Работаем с ними после разбора теплых",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, входящие в холодный сегмент. Работаем с ними после разбора теплых",
                        ],
                    ],
                ],
                [
                    'name' => 'Теплые',
                    'sort' => 40,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, входящие в холодный сегмент. Работаем с ними после разбора горячих",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, входящие в холодный сегмент. Работаем с ними после разбора горячих",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, входящие в холодный сегмент. Работаем с ними после разбора горячих",
                        ],
                    ],
                ],
                [
                    'name' => 'Горячие',
                    'sort' => 50,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, входящие в холодный сегмент. Работаем с ними в первую очередь",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, входящие в холодный сегмент. Работаем с ними в первую очередь",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, входящие в холодный сегмент. Работаем с ними в первую очередь",
                        ],
                    ],
                ],
                [
                    'name' => 'Взят в работу',
                    'sort' => 60,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиентов с которыми начали работать перетаскиваем в этот этап",
                        ],
                    ],
                ],
                [
                    'name' => 'Консультация проведена',
                    'sort' => 70,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Те клиенты, которым провели консультацию",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Те клиенты, которым провели консультацию",
                        ],
                        [
                            "level" => "master",
                            "description" => "Те клиенты, которым провели консультацию",
                        ],
                    ],
                ],
                [
                    'name' => 'Счет выставлен',
                    'sort' => 80,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "На этом этапе клиенты, которые оплачивают",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "На этом этапе клиенты, которые оплачивают",
                        ],
                        [
                            "level" => "master",
                            "description" => "На этом этапе клиенты, которые оплачивают",
                        ],
                    ],
                ],
                [
                    'name' => 'Счет оплачен',
                    'sort' => 90,
                    "color" => "#fffeb2",
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, которые оплатили и ждут закрытия в успешный этап",
                        ],
                    ],
                ],
            ]
        ],
        [
            'name' => 'Прогрев',
            "is_main" => false,
            "sort" => 50,
            'statuses' => [
                [
                    'name' => 'Новый лид',
                    'sort' => 20,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                        [
                            "level" => "master",
                            "description" => "Новые клиенты находятся в этом этапе, пока их не возьмут в работу",
                        ],
                    ],
                ],
                [
                    'name' => 'Касание 1',
                    'sort' => 30,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Проводим касание с клиентом",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Проводим касание с клиентом",
                        ],
                        [
                            "level" => "master",
                            "description" => "Проводим касание с клиентом",
                        ],
                    ],
                ],
                [
                    'name' => 'Касание 2',
                    'sort' => 40,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Проводим касание с клиентом",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Проводим касание с клиентом",
                        ],
                        [
                            "level" => "master",
                            "description" => "Проводим касание с клиентом",
                        ],
                    ],
                ],
                [
                    'name' => 'Касание 3',
                    'sort' => 50,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Проводим касание с клиентом",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Проводим касание с клиентом",
                        ],
                        [
                            "level" => "master",
                            "description" => "Проводим касание с клиентом",
                        ],
                    ],
                ],
                [
                    'name' => 'Клиент ответил',
                    'sort' => 60,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "На этом этапе клиент ответил на одно из касаний",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "На этом этапе клиент ответил на одно из касаний",
                        ],
                        [
                            "level" => "master",
                            "description" => "На этом этапе клиент ответил на одно из касаний",
                        ],
                    ],
                ],
            ]
        ],
        [
            'name' => 'Рассылки',
            "is_main" => false,
            "sort" => 60,
            'statuses' => [
                [
                    'name' => 'Ждет рассылки',
                    'sort' => 10,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, ждущие рассылки в этом этапе",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, ждущие рассылки в этом этапе",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, ждущие рассылки в этом этапе",
                        ],
                    ],
                ],
                [
                    'name' => 'Отправлено',
                    'sort' => 20,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, которым отправили сообщение",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, которым отправили сообщение",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, которым отправили сообщение",
                        ],
                    ],
                ],
                [
                    'name' => 'Ответ получен',
                    'sort' => 30,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, ответившие на рассылку",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, ответившие на рассылку",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, ответившие на рассылку",
                        ],
                    ],
                ],
            ]
        ],
        [
            'name' => 'Сервис',
            "is_main" => false,
            "sort" => 70,
            'statuses' => [
                [
                    'name' => 'Новый ученик',
                    'sort' => 10,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, которые купили недавно и их нужно сопровождать в тех вопросах",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, которые купили недавно и их нужно сопровождать в тех вопросах",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, которые купили недавно и их нужно сопровождать в тех вопросах",
                        ],
                    ],
                ],
                [
                    'name' => 'Контакт установлен',
                    'sort' => 40,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Связались с клиентом и уточнили что все ок",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Связались с клиентом и уточнили что все ок",
                        ],
                        [
                            "level" => "master",
                            "description" => "Связались с клиентом и уточнили что все ок",
                        ],
                    ],
                ],
                [
                    'name' => 'Ожидает первого урока',
                    'sort' => 50,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Помогли с тех вопросами, клиенты ждут первый урок",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Помогли с тех вопросами, клиенты ждут первый урок",
                        ],
                        [
                            "level" => "master",
                            "description" => "Помогли с тех вопросами, клиенты ждут первый урок",
                        ],
                    ],
                ],
                [
                    'name' => 'Первый урок пройден',
                    'sort' => 60,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты прошли первый урок, задача воронки выполнена",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты прошли первый урок, задача воронки выполнена",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты прошли первый урок, задача воронки выполнена",
                        ],
                    ],
                ],
                [
                    'name' => 'Активный ученик',
                    'id' => 142,
                    "descriptions" => [
                        [
                            "level" => "newbie",
                            "description" => "Клиенты, которые продолжают обучение",
                        ],
                        [
                            "level" => "candidate",
                            "description" => "Клиенты, которые продолжают обучение",
                        ],
                        [
                            "level" => "master",
                            "description" => "Клиенты, которые продолжают обучение",
                        ],
                    ],
                ],
            ]
        ]
    ];

    public function handle()
    {
        $user = User::query()->find($this->argument('user_id'));

        $amoApi = (new Client($user->account))
            ->setDelay(0.5)
            ->initLogs(Env::get('APP_DEBUG'));

        $newPipelines = [];

        foreach ($this->pipelines as $pipeline) {
            $pipelineDetail = $amoApi->service->ajax()->postJson('/api/v4/leads/pipelines', [
                [
                    "name" => $pipeline['name'],
                    "is_main" => $pipeline['is_main'],
                    "is_unsorted_on" => false,
                    "sort" => $pipeline['sort'],
                    "_embedded" => [
                        'statuses' => array_reverse($pipeline['statuses'])
                    ]
                ]
            ]);

            $pipelineId = $pipelineDetail->_embedded->pipelines[0]->id;

            $newPipelines[] = $pipelineId;

            foreach ($pipelineDetail->_embedded->pipelines[0]->_embedded->statuses as $statusDetail) {
                foreach ($pipeline['statuses'] as $status) {
                    if ($status['name'] == $statusDetail->name) {
                        $amoApi->service->ajax()->patch(
                            '/api/v4/leads/pipelines/' . $pipelineId . '/statuses/' . $statusDetail->id,
                            [
                                'descriptions' => $status['descriptions'],
                            ]
                        );
                    }
                }
            }
        }

        $pipelines = $amoApi->service->ajax()->get('/api/v4/leads/pipelines');

        $lastPipelineId = end($pipelines->_embedded->pipelines)->id;

        //TODO протестить!
        foreach ($pipelines->_embedded->pipelines as $pipeline) {
            if (!in_array($pipeline->id, $newPipelines)) {
                //удаляем последнюю воронку (в акке по умолчанию) старым апи
                $amoApi->service->ajax()->post('/private/api/v2/json/pipelines/delete', [

                    'request' => [
                        'id' => $lastPipelineId
                    ]
                ]);
            }
        }
    }
}
