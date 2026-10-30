<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Http\{Csrf,Request,Response,View};
use Allfather\Loopia\Http\ReadAdapters\HistoryReviewAdapter;
use Allfather\Loopia\Workflow\HistoryReviewCommandCandidate;

/**
 * UI-02 scriptless workbench. Not registered in Kernel while U03/U06/U07/U09
 * owner decisions remain unadopted. Runtime adapter refuses without its fixed
 * CLI-only fixture owner. Kernel still owns global auth/CSRF when adopted.
 */
final class HistoryReviewController
{
    public function __construct(
        private readonly HistoryReviewAdapter $reader,
        private readonly HistoryReviewCommandCandidate $commands,
        private readonly View $view,
    ){}

    public function show(Request $request,?array $session):Response
    {
        if($request->method!=='GET')return Response::html('Method not allowed',405);
        if($session===null)return Response::redirect('/login');
        [$competitor,$question,$page]=self::selector($request,false);
        $read=$this->reader->list($session,$competitor,$question,$page);
        return $this->render($session,$read,null,null,200,$request->requestId,
            $competitor,$question);
    }

    public function submit(Request $request,?array $session):Response
    {
        if($request->method!=='POST')return Response::html('Method not allowed',405);
        if($session===null)return Response::redirect('/login');
        // Kernel already checks all unsafe methods. This direct controller
        // boundary independently refuses tests or future unguarded callers.
        if(!Csrf::verify($request->body('csrf_token'),
            (string)($session['csrf_token_hash']??''))){
            return Response::json([
                'decision'=>'DENIED','reason_codes'=>['UI02_CSRF_INVALID'],
            ],403);
        }
        [$competitor,$question]=self::selector($request,true);
        $revision=$request->body('expected_revision');
        $action=$request->body('review_action');
        $reason=$request->body('reason_code');
        $mode=$request->body('command_mode');
        if(!in_array($mode,['plan','propose'],true)){
            return Response::json([
                'decision'=>'DENIED','reason_codes'=>['UI02_COMMAND_MODE_INVALID'],
            ],409);
        }
        $out=$mode==='plan'
            ?$this->commands->reviewPlan($session,$competitor,$question,$revision,$action,$reason)
            :$this->commands->submit($session,$competitor,$question,$revision,$action,
                $reason,$request->body('plan_digest'));
        $read=$this->reader->list($session,$competitor,$question);
        return $this->render(
            $session,$read,$out->isOk()?$out->value():null,
            $out->isOk()?null:$out,
            $out->isOk()?200:($out->isFailure()?500:409),
            $request->requestId,$competitor,$question,
        );
    }

    private static function selector(Request $request,bool $post):array
    {
        $pick=static fn(string $field):string=>$post
            ?$request->body($field):$request->input($field);
        $page=$post?1:(preg_match('/^[1-9][0-9]?$/D',$pick('page'))===1
            ?(int)$pick('page'):1);
        return [$pick('competitor'),$pick('question'),$page];
    }

    private function render(?array $session,array $read,?array $command,
                            ?Outcome $error,int $status,string $requestId,
                            string $competitor,string $question):Response
    {
        // No raw source text, prompt, model response or private input bytes
        // leave the read adapter, and templates escape all metadata labels.
        $allowed=$read['decision']==='VISIBLE';
        if(!$allowed && $status===200){
            $status=match($read['decision']){
                'NO_ACTIVE_WORKSPACE'=>409,'DENIED'=>403,
                'NOT_FOUND'=>404,default=>503,
            };
        }
        return Response::html($this->view->render('history-review',[
            'title'=>'Historical review workbench',
            'session'=>$session,'nav'=>['current'=>'history-review'],
            'read'=>$read,'command'=>$command,
            'errorCodes'=>$error?->reasonCodes??[],
            'requestId'=>$requestId,'competitor'=>$competitor,
            'question'=>$question,'actions'=>HistoryReviewCommandCandidate::ACTIONS,
            'reasons'=>HistoryReviewCommandCandidate::REASONS,
        ]),$status);
    }
}
