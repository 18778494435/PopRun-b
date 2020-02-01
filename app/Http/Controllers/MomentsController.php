<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use Storage;
use App\RUsers;
use App\RMoments;
use App\Comments;
use App\LinkULikeMs;
use App\RMomentImgs;

class MomentsController extends Controller
{
    // 发布动态
    public function doMoment(Request $request){
        if($request->has('rid')){
            if($request->has('text') || $request->has('imgs')){
                $user = RUsers::where('rid', $request->rid);
                if($user->first()){
                    $moment = new RMoments();
                    $moment->fillable(['rid', 'text']);
                    $moment->fill([
                        'rid' => $request->rid,
                        'text' => $request->has('text') ? $request->text : ""
                    ]);
                    try {
                        if($moment->save()){
                            $original = []; $thumbnail = []; $i = 0;
                            foreach($request->imgs as $img){
                                $img['moid'] = $moment->id;
                                $momentImg = new RMomentImgs();
                                $momentImg->fillable(array_keys($img));
                                $momentImg->fill($img);
                                $momentImg->save();

                                $original[$i]['url'] = $img['original'];
                                $original[$i]['width'] = $img['width'];
                                $original[$i]['height'] = $img['height'];
                                $thumbnail[$i]['url'] = $img['thumbnail'];
                                $thumbnail[$i]['width'] = $img['mwidth'];
                                $thumbnail[$i]['height'] = $img['mheight'];
                                $i++;
                            }
                            // 返回数据
                            $data = $moment;
                            $data['moid'] = $moment->id; unset($data['id']); //修改id为moid，与数据库保持一致
                            $data['imgs'] = [
                                'original' => $original,
                                'thumbnail' => $thumbnail
                            ];
                            return returnData(true, "操作成功", $data);
                        }
                    } catch (\Throwable $th) {
                        return returnData(false, $th->errorInfo[2], null);
                    }
                }else{
                    return returnData(false, '不存在该用户', null);
                }
            }else{
                return returnData(false, "需要文字内容或者图片", null);
            }
        }else{
            return returnData(false, "缺rid", null);
        }
    }

    //删除动态
    public function delMoment(Request $request){
        if($request->has('rid') && $request->has('moid')){
            DB::beginTransaction(); //事务开始
            try {
                Comments::where('moid', $request->moid)->delete(); //删除评论
                LinkULikeMs::where('moid', $request->moid)->delete(); //删除点赞
                RMomentImgs::where('moid', $request->moid)->delete(); //删除图片
                // Stroage::delete()  //删除图片文件  技术原因暂时不做
                RMoments::where('moid', $request->moid)->where('rid', $request->rid)->delete(); //删除动态
                DB::commit(); //提交事务
                return returnData(true, "操作成功", null);
            } catch (\Throwable $th) {
                DB::rollback(); //回滚
                return returnData(false, $th->errorInfo[2], $th);
            }
        }else{
            return returnData(false, "缺rid或者moid", null);
        }
    }

    // 发表评论 遗留：缺少判断是否存在moid
    public function doComment(Request $request){
        if($request->has('rid') && $request->has('moid')){
            $comment = new Comments();
            $comment->fill([
                'rid' => $request->rid,
                'moid' => $request->moid,
                'comment' => $request->comment
            ]);
            try {
                if($comment->save()){
                    // 返回数据
                    $data = $comment;
                    $data['coid'] = $comment->id; unset($data['id']); //修改id为coid，与数据库保持一致
                    return returnData(true, "操作成功", $data);
                }
            } catch (\Throwable $th) {
                return returnData(false, $th->errorInfo[2], null);
            }
        }else{
            return returnData(false, "缺rid或者moid", null);
        }
    }

    // 点赞  遗留：缺少是否已点赞判断
    public function doLike(Request $request){
        if($request->has('rid') && $request->has('moid')){
            $like = new LinkULikeMs();
            $like->fill([
                'rid' => $request->rid,
                'moid' => $request->moid
            ]);
            try {
                if($like->save()){
                    return returnData(true, "操作成功", $like);
                }else{
                    return returnData(false, "操作失败", $like);
                }
            } catch (\Throwable $th) {
                return returnData(false, $th->errorInfo[2], null);
            }
        }else{
            return returnData(false, "缺rid或者moid", null);
        }
    }
    
    // 取消点赞
    public function doDislike(Request $request){
        if($request->has('rid') && $request->has('moid')){
            try {
                LinkULikeMs::where('rid', $request->rid)->where('moid', $request->moid)->delete();
                return returnData(true, "操作成功", null);
            } catch (\Throwable $th) {
                return returnData(false, $th->errorInfo[2], null);
            }
        }else{
            return returnData(false, "缺rid或者moid", null);
        }
    }

    // 获取个人动态
    public function getMine(Request $request){
        if($request->has('rid')){
            $request->has('pageindex') ? $pageindex = $request->pageindex+1 : $pageindex = 1;  //当前页 1,2,3,...,首次查询可以传0
            $request->has('pagesize') ? $pagesize = $request->pagesize : $pagesize = 10;  //页面大小
            $pageall = null; //总页数
            try {
                $moments = RMoments::where('rid', $request->rid)->get();
                $moment_num = count($moments); //动态总条数
                $pageall = ceil($moment_num/$pagesize); //计算总页数
                $data = []; //动态联合数据
                for($i = ($pageindex-1)*$pagesize,$n=0; $i<($pageindex-1)*$pagesize+$pagesize && $i<$moment_num; $i++,$n++){
                    $data[$n]= $moments[$i];
                    //获取评论
                    $data[$n]['comments'] = Comments::join('r_users', 'r_users.rid', '=', 'comments.rid')
                                                    ->where('moid', $moments[$i]['moid'])
                                                    ->select('comments.*', 'r_users.nickname')
                                                    ->get();
                    //获取点赞
                    $data[$n]['likes'] = LinkULikeMs::join('r_users', 'r_users.rid', '=', 'link_u_like_ms.rid')
                                                    ->where('moid', $moments[$i]['moid'])
                                                    ->select('link_u_like_ms.*', 'r_users.img')
                                                    ->get();
                    //获取图片
                    $imgs = RMomentImgs::where('moid', $moments[$i]['moid'])->get();
                    $original = []; $thumbnail = [];
                    foreach($imgs as $img){
                        $original []= [
                            "url" => $img->original,
                            "width" => $img->width,
                            "height" => $img->height
                        ];
                        $thumbnail []= [
                            "url" => $img->thumbnail,
                            "width" => $img->mwidth,
                            "height" => $img->mheight
                        ];
                    }
                    $data[$n]['imgs'] = [
                        'original' => $original,
                        'thumbnail' => $thumbnail
                    ];
                }
                //返回数据处理
                $re = [
                    'rid' => $request->rid,
                    'pageindex' => $pageindex,
                    'pagesize' => $pagesize,
                    'pageall' => $pageall,
                    'moments' => $data
                ];
                return returnData(true, "操作成功", $re);
            } catch (\Throwable $th) {
                return returnData(false, "$th->errorInfo[2]", $th);
            }
        }else{
            return returnData(false, "缺少rid", null);
        }
    }
}