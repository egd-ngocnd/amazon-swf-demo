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
require_once 'BasicWorkflowWorker.php' ;
require_once "Config.php";

$swf = new AmazonSWF();

echo "Starting workflow worker polling\n";
$decider_task_list = 'deciderTaskList';
$workflow_worker = new BasicWorkflowWorker($swf, Config::DOMAIN, $decider_task_list);
$workflow_worker->start();
