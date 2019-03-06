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
require_once "Config.php";
/*
 * A decider can be written by modeling the workflow as a state machine.
 * For complex workflows, this is the easiest model to use.
 *
 * The decider reads the history to figure out which state the workflow is currently in,
 * and makes a decision based on the current state.
 *
 * This implementation of the decider ignores activity failures.
 * You can handle them by adding more states.
 * This decider also only supports having a single activity open at a time.
 */
abstract class BasicWorkflowWorkerStates {
    // A new workflow is in this state
    const START = 0;
    // If an activity is open, and not a timer.
    const ACTIVITY_COMPLETE = 1;
    
    const SECOND_ACTIVITY_COMPLETE = 2;
    // Nothing is open.
    const NOTHING_OPEN = 3;
}

/*
 * At some point it makes sense to separate polling logic and worker logic, but we've left
 * them together here for simplicity.
 */
class BasicWorkflowWorker
{
    const DEBUG = true;
    protected $swf;
    protected $domain;
    protected $task_list;

    public function __construct(AmazonSWF $swf_service, $domain, $task_list)
    {
        $this->domain = $domain;
        $this->task_list = $task_list;
        $this->swf = $swf_service;
    }

    public function start()
    {
        $this->_poll();
    }

    protected function _poll()
    {
        while (true) {
            $opts = array(
                'domain' => $this->domain,
                'taskList' => array(
                    'name' => $this->task_list
                )
            );

            $response = $this->swf->poll_for_decision_task($opts);


            if ($response->isOK()) {
                $task_token = (string)$response->body->taskToken;

                if (!empty($task_token)) {
                    if (self::DEBUG) {
                        echo "Got history; handing to decider\n";
                    }

                    $history = $response->body->events();
                    try {
                        $decision_list = self::_decide($history);
                    } catch (Exception $e) {
                        // If failed decisions are recoverable, one could drop the task and allow it to be redriven by the task timeout.
                        echo 'Failing workflow; exception in decider: ', $e->getMessage(), "\n", $e->getTraceAsString(), "\n";
                        $decision_list = array(
                            wrap_decision_opts_as_decision('FailWorkflowExecution', array(
                                'reason' => substr('Exception in decider: ' . $e->getMessage(), 0, 256),
                                'details' => substr($e->getTraceAsString(), 0, 32768)
                            ))
                        );
                    }
                    $complete_opt = [
                        'taskToken' => $task_token,
                        "decisions" => $decision_list
                    ];
                    $complete_response = $this->swf->respond_decision_task_completed($complete_opt);

                    if ($complete_response->isOK()) {
                        echo "RespondDecisionTaskCompleted SUCCESS\n";
                    } else {
                        // a real application may want to report this failure and retry
                        echo "RespondDecisionTaskCompleted FAIL\n";
                        echo "Response body: \n";
                        print_r($complete_response->body);
                        //echo "Request JSON: \n";
                        //echo json_encode($complete_opt) . "\n";
                    }
                } else {
                    echo "PollForDecisionTask received empty response\n";
                }
            } else {
                echo 'ERROR: ';
                print_r($response->body);

                sleep(2);
            }
            //return 0;
        }
    }

    /**
     * A decider inspects the history of a workflow and then schedules more tasks based on the current state of
     * the workflow.
     */
    protected static function _decide($history)
    {
        $workflow_state = BasicWorkflowWorkerStates::START;
        $decision_list = null;
        foreach ($history as $event) {
            $event_type = $event->eventType;
             switch ($event_type){
                 case 'ActivityTaskCanceled':
                     break;
                 case 'ActivityTaskFailed':
                     break;
                 case 'ActivityTaskTimedOut':
                     break;
                 case 'ActivityTaskScheduled':
                     break;
                 case "ActivityTaskCompleted":
                     if ($workflow_state === BasicWorkflowWorkerStates::START){
                         $workflow_state = BasicWorkflowWorkerStates::ACTIVITY_COMPLETE;
                         $decision_list = self::_createDecision(2,$event->activityTaskCompletedEventAttributes->result);
                     } elseif($workflow_state === BasicWorkflowWorkerStates::ACTIVITY_COMPLETE){
                         $workflow_state = BasicWorkflowWorkerStates::SECOND_ACTIVITY_COMPLETE;
                         $decision_list = [];
                     }elseif($workflow_state === BasicWorkflowWorkerStates::SECOND_ACTIVITY_COMPLETE){
                         $workflow_state = BasicWorkflowWorkerStates::NOTHING_OPEN;
                         $decision_list = [];
                     }
                     break;
                 case "WorkflowExecutionStarted":
                     $workflow_state = BasicWorkflowWorkerStates::START;
                     $decision_list = self::_createDecision(1,$event->workflowExecutionStartedEventAttributes->input);
                     break;
             }
        }
        print_r("Current status: $workflow_state\n");
        if ($decision_list != null){
            return [
                $decision_list
            ];
        }
        // nothing to do
        return [
            [
                "decisionType" => "CompleteWorkflowExecution",
            ]
        ];
    }
    protected  static function _createDecision($type,$input){
        $activity_type = array(
            "name" => Config::FIRST_ACTIVITY_NAME,
            "version" => Config::FIRST_ACTIVITY_VERSION,
        );
        $task_list = array(
            "name" => Config::FIRST_ACTIVITY_TASK_LIST,
        );
        if($type == 2) {
            $activity_type = array(
                "name" => Config::SECOND_ACTIVITY_NAME,
                "version" => Config::SECOND_ACTIVITY_VERSION
            );
            $task_list = array(
                "name" => Config::SECOND_ACTIVITY_TASK_LIST
            );
        }
        return array(
            "decisionType" => "ScheduleActivityTask",
            "scheduleActivityTaskDecisionAttributes" => array(
                "activityType" => $activity_type,
                "activityId" => "myActivity-" .time(),
                "control" => "this is a sample message",
                // Customize timeout values
                "scheduleToCloseTimeout" => "360",
                "scheduleToStartTimeout" => "300",
                "startToCloseTimeout" => "60",
                "heartbeatTimeout" => "60",
                "taskList" => $task_list,
                "input" => json_encode($input),
            )
        );
    }
}