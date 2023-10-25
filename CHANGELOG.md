sue\coroutine
==================================================================================================

## 1.3.0 (2023-10-25)
* 【修改】 更新[sue\event-loop](https://github.com/reactphp/event-loop.git)最低依赖为1.2.0
* 【修改】 标记`\Sue\Coroutine\go($callable)`方法为deprecated, 未来版本移除
* 【新增】 新增`\Sue\Coroutine\coAs($class_name, $callable)`方法来指定自定义的coroutine class来执行协程
* 【新增】 新增`\Sue\Coroutine\async($callable)`方法来阻塞运行一段协程