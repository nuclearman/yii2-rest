<?php

namespace zhuravljov\yii\rest\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use zhuravljov\yii\rest\helpers\ArrayHelper;
use zhuravljov\yii\rest\models\RequestEvent;
use zhuravljov\yii\rest\models\RequestForm;
use zhuravljov\yii\rest\models\ResponseEvent;
use zhuravljov\yii\rest\models\ResponseRecord;
use zhuravljov\yii\rest\Module;

/**
 * Class DefaultController
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
class DefaultController extends Controller
{
    /**
     * @var \zhuravljov\yii\rest\Module
     */
    public $module;
    /**
     * @inheritdoc
     */
    public $defaultAction = 'request';

    public function actionRequest($tag = null)
    {
        /** @var RequestForm $model */
        $model = Yii::createObject(RequestForm::className());
        $record = new ResponseRecord();

        if (
            $tag !== null &&
            !$this->module->storage->load($tag, $model, $record)
        ) {
            throw new NotFoundHttpException('Request not found.');
        }

        if (
            $model->load(Yii::$app->request->post()) &&
            $model->validate()
        ) {
            $record = $this->send($model);
            $tag = $this->module->storage->save($model, $record);

            return $this->redirect(['request', 'tag' => $tag, '#' => 'response']);
        }

        $model->addEmptyRows();


        $history = $this->module->storage->getHistory();
        $collection = $this->module->storage->getCollection();

        foreach ($history as $_tag => &$item) {
            $item['in_collection'] = isset($collection[$_tag]);
        }
        unset($item);
        // TODO Grouping will move to the config level
        $collection = ArrayHelper::group($collection, function ($row) {
            if (preg_match('|[^/]+|', ltrim($row['endpoint'], '/'), $m)) {
                return $m[0];
            } else {
                return 'common';
            }
        });

        return $this->render('request', [
            'tag' => $tag,
            'baseUrl' => rtrim($this->module->baseUrl, '/') . '/',
            'model' => $model,
            'record' => $record,
            'history' => $history,
            'collection' => $collection,
        ]);
    }

    public function actionRemoveFromHistory($tag)
    {
        if ($this->module->storage->exists($tag)) {
            $this->module->storage->removeFromHistory($tag);

            return $this->redirect(['request']);
        } else {
            throw new NotFoundHttpException('Request not found.');
        }
    }

    public function actionAddToCollection($tag)
    {
        if ($this->module->storage->exists($tag)) {
            $this->module->storage->addToCollection($tag);

            return $this->redirect(['request', 'tag' => $tag]);
        } else {
            throw new NotFoundHttpException('Request not found.');
        }
    }

    public function actionRemoveFromCollection($tag)
    {
        if ($this->module->storage->exists($tag)) {
            $this->module->storage->removeFromCollection($tag);

            return $this->redirect(['request']);
        } else {
            throw new NotFoundHttpException('Request not found.');
        }
    }

    /**
     * @param RequestForm $model
     * @return ResponseRecord
     */
    protected function send(RequestForm $model)
    {
        $this->module->trigger(Module::EVENT_ON_REQUEST, new RequestEvent([
            'form' => $model,
        ]));

        /** @var \yii\httpclient\Client $client */
        $client = Yii::createObject($this->module->clientConfig);
        $client->baseUrl = $this->module->baseUrl;

        $begin = microtime(true);
        $response = $client->createRequest()
            ->setMethod($model->method)
            ->setUrl($model->getUri())
            ->setData($model->getBodyParams())
            ->setHeaders($model->getHeaders())
            ->send();
        $duration = microtime(true) - $begin;

        $record = new ResponseRecord();
        $record->status = $response->getStatusCode();
        $record->duration = $duration;
        foreach ($response->getHeaders() as $name => $values) {
            $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
            $record->headers[$name] = $values;
        }
        $record->content = $response->getContent();

        $this->module->trigger(Module::EVENT_ON_RESPONSE, new ResponseEvent([
            'form' => $model,
            'record' => $record,
        ]));

        return $record;
    }
}
