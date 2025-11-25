<?php

namespace modules\knowledgebase;

use Craft;
use craft\console\Application as ConsoleApplication;
use yii\base\Module as BaseModule;
use modules\knowledgebase\console\controllers\KnowledgebaseController;
use modules\knowledgebase\services\KnowledgebaseService;

class KnowledgebaseModule extends BaseModule
{
    public static ?KnowledgebaseModule $instance = null;

    public function init(): void
    {
        parent::init();

        self::$instance = $this;

        Craft::setAlias('@modules/knowledgebase', __DIR__);

        $this->setComponents([
            'knowledgebase' => KnowledgebaseService::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            Craft::$app->controllerMap['kb'] = KnowledgebaseController::class;
        }
    }

    public static function getInstance(): KnowledgebaseModule
    {
        return self::$instance ?? throw new \RuntimeException('Knowledgebase module not initialized.');
    }

    public function getService(): KnowledgebaseService
    {
        /** @var KnowledgebaseService $service */
        $service = $this->get('knowledgebase');
        return $service;
    }
}
