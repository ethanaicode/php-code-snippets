PHP 代码脚本片段

目前包含：

- `discuzDsuAutoSign.php`: Discuz 论坛自动签到脚本的 PHP 实现

```php
# 推荐定时任务
# 每 12 小时签到一次（0/12 点，为了避免一次失败）
0 */12 * * * /usr/bin/php /path/to/discuzDsuAutoSign.php --mode=sign
# 每 6 小时领取一次矿工收益(0/6/12/18 点)
0 */6 * * * /usr/bin/php /path/to/discuzDsuAutoSign.php --mode=miner
```