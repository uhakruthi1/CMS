<?php

namespace modules\knowledgebase\console\controllers;

use Craft;
use craft\console\Controller;
use craft\console\ExitCode;
use modules\knowledgebase\KnowledgebaseModule;

class KnowledgebaseController extends Controller
{
    public $defaultAction = 'install';

    public function actionInstall(): int
    {
        $module = KnowledgebaseModule::getInstance();
        $module->getService()->ensureContentModel();
        $module->getService()->seed();
        Craft::$app->projectConfig->rebuild();
        $this->stdout("Knowledge base content model and demo data installed.\n");
        return ExitCode::OK;
    }
}
