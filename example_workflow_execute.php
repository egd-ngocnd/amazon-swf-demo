<?php
/*
 * Copyright 2012-2013 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 *  http://aws.amazon.com/apache2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */
require_once dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR . 'sdk.class.php';
require_once 'BasicActivityWorker.php' ;
require_once 'BasicWorkflowWorker.php' ;
require_once 'Config.php';

$swf = new AmazonSWF();
$workflow_input = json_encode(array(
    "input" => 'World',
));

$decider_task_list = 'deciderTaskList';

$opts = array(
    'domain' => Config::DOMAIN,
    'workflowId' => 'myWorkflowId-' . time(),
    'workflowType' => array(
        'name' => Config::WORKFLOW_NAME,
        'version' => Config::WORKFLOW_VERSION
    ),
    'input' => $workflow_input,
    'childPolicy' => 'TERMINATE',
    // This is what specifying a task list at scheduling time looks like.
    // You can also register a type with a default task list and not specify one at scheduling time.
    // The value provided at scheduling time always takes precedence.
    'taskList' => array('name' => $decider_task_list),
    // This is what specifying timeouts at scheduling time looks like.
    // You can also register types with default timeouts and not specify them at scheduling time.
    // The value provided at scheduling time always takes precedence.
    'taskStartToCloseTimeout' => '10',
    'executionStartToCloseTimeout' => '300'
);

$response = $swf->startWorkflowExecution($opts);

if ($response->isOK()) {
    echo 'Workflow started: ' . json_encode($opts) . ' - runId: ' . $response->body->runId . "\n";
} else {
    print_r($response->body);
}
