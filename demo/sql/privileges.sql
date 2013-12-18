GRANT USAGE ON mysql.* TO 'pma'@'192.168.30.%' IDENTIFIED BY 'heslo';
GRANT SELECT (
Host, User, Select_priv, Insert_priv, Update_priv, Delete_priv,
Create_priv, Drop_priv, Reload_priv, Shutdown_priv, Process_priv,
File_priv, Grant_priv, References_priv, Index_priv, Alter_priv,
Show_db_priv, Super_priv, Create_tmp_table_priv, Lock_tables_priv,
Execute_priv, Repl_slave_priv, Repl_client_priv
) ON mysql.user TO 'pma'@'192.168.30.%';
GRANT SELECT ON mysql.db TO 'pma'@'192.168.30.%';
GRANT SELECT ON mysql.host TO 'pma'@'192.168.30.%';
GRANT SELECT (Host, Db, User, Table_name, Table_priv, Column_priv)
ON mysql.tables_priv TO 'pma'@'192.168.30.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON phpmyadmin.* TO 'pma'@'192.168.30.%';


GRANT ALL PRIVILEGES ON *.* TO 'root'@'192.168.30.%';
