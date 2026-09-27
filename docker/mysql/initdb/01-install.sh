# 首次创建数据卷时由 mysql 官方镜像 source 执行（文件不能有可执行权限，
# 否则入口脚本会在子 shell 里运行它，docker_process_sql 函数就不可用了）。
# 作用：导入 V2board 的 database/install.sql。

V2B_INSTALL_SQL=/v2b-database/install.sql
if [ ! -f "$V2B_INSTALL_SQL" ]; then
  echo "[v2b-mysql] 找不到 $V2B_INSTALL_SQL，请先在宿主机运行 scripts/sync-src.sh" >&2
  exit 1
fi

echo "[v2b-mysql] 导入 $V2B_INSTALL_SQL 到 ${MYSQL_DATABASE} ..."
docker_process_sql --database="$MYSQL_DATABASE" < "$V2B_INSTALL_SQL"
echo "[v2b-mysql] 导入完成"
