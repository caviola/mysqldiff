<?php

/**
 * mysqldiff
 * 
 * First of all: THIS IS AN ALPHA VERSION. DO NOT USE ON PRODUCTION!
 * 
 * Compares the schema of two MySQL databases and produces a script 
 * to "alter" the second schema to match the first one.
 * 
 * Copyright (c) 2010-2011, Albert Almeida (caviola@gmail.com)
 * All rights reserved.
 * 
 * THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
 * SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 * 
 */
 
function populate_schemata_info(&$db) {
    if (!($result = mysql_query("select * from information_schema.schemata where schema_name='$db->database'", $db->link)))
        return FALSE;
    if ($info = mysql_fetch_object($result)) {
        $db->charset = $info->DEFAULT_CHARACTER_SET_NAME;
        $db->collation = $info->DEFAULT_COLLATION_NAME;
    }
}

function list_tables($db) {
    if (!($result = mysql_query("select TABLE_NAME, ENGINE, TABLE_COLLATION, ROW_FORMAT, CHECKSUM, TABLE_COMMENT from information_schema.tables where table_schema='$db->database'", $db->link)))
        return FALSE;
    $tables = array();
    while ($row = mysql_fetch_object($result)) {
        $tables[$row->TABLE_NAME] = $row;
    }
    return $tables;
}

function list_columns($table, $db) {
    // Note the columns are returned in ORDINAL_POSITION ascending order.
    if (!($result = mysql_query("select * from information_schema.columns where table_schema='$db->database' and table_name='$table' order by ordinal_position", $db->link)))
        return FALSE;
    
    $columns = array();
    while ($row = mysql_fetch_object($result)) {
        $columns[$row->COLUMN_NAME] = $row;
    }
    return $columns;
}

function list_indexes($table, $db) {
    if (!($result = mysql_query("show indexes from `$table`", $db->link)))
        return FALSE;
    
    $indexes = array();
    $prev_key_name = NULL;
    while ($row = mysql_fetch_object($result)) {
        // Get the information about the index column.
        $index_column = (object)array(
            'sub_part' => $row->Sub_part,
            'seq' => $row->Seq_in_index,
            'type' => $row->Index_type,
            'collation' => $row->Collation,
            'comment' => $row->Comment,
        );
        if ($row->Key_name != $prev_key_name) {
            // Add a new index to the list.
            $indexes[$row->Key_name] = (object)array(
                'key_name' => $row->Key_name,
                'table' => $row->Table,
                'non_unique' => $row->Non_unique,
                'columns' => array($row->Column_name => $index_column)
            );
            $prev_key_name = $row->Key_name;
        } else {
            // Add a new column to an existing index.
            $indexes[$row->Key_name]->columns[$row->Column_name] = $index_column;
        }
    }
    
    return $indexes;
}

function get_create_table_sql($name, $db) {
    if (!($result = mysql_query("show create table `$name`", $db->link)))
        return FALSE;
    
    $row = mysql_fetch_row($result);
    return $row[1];
}

function create_tables($db1, $tables1, $tables2) {
    global $options;
    
    $sql = '';
    $table_names = array_diff(array_keys($tables1), array_keys($tables2));
    foreach ($table_names as $t) {
        $sql .= get_create_table_sql($t, $db1) . ";\n\n";
    }
    
    fputs($options->ofh, $sql);
}

function format_default_value($value, $db) {
    if (strcasecmp($value, 'CURRENT_TIMESTAMP') == 0)
        return $value;
    elseif (is_string($value)) 
        return "'" . mysql_real_escape_string($value, $db->link) . "'";
    else
        return $value;
}

function drop_tables($tables1, $tables2) {
    global $options;
    
    $sql = '';
    $table_names = array_diff(array_keys($tables2), array_keys($tables1));
    foreach ($table_names as $t) {
        $sql .= "DROP TABLE `$t`;\n";
    }
    
    if ($sql)
        $sql .= "\n";
    
    fputs($options->ofh, $sql);
}

function build_column_definition_sql($column, $db) {
    $result = $column->COLUMN_TYPE;
    
    if ($column->COLLATION_NAME) 
        $result .= " COLLATE '$column->COLLATION_NAME'";
    
    $result .= strcasecmp($column->IS_NULLABLE, 'NO') == 0 ? ' NOT NULL' : ' NULL';
    
    if (isset($column->COLUMN_DEFAULT))
        $result .= ' DEFAULT ' . format_default_value($column->COLUMN_DEFAULT, $db);
    
    if ($column->EXTRA)
        $result .= " $column->EXTRA";
    
    if ($column->COLUMN_COMMENT)
        $result .= " COMMENT '" . mysql_real_escape_string($column->COLUMN_COMMENT, $db->link) . "'";
        
    return $result;
}

function alter_table_add_column($column, $after_column, $table, $db) {
    global $options;
    
    $sql = "ALTER TABLE `$table` ADD COLUMN `$column->COLUMN_NAME` " . 
        build_column_definition_sql($column, $db) .
        ($after_column ? " AFTER `$after_column`" : ' FIRST') . 
        ";\n";
    
    fputs($options->ofh, $sql);
}

function alter_table_modify_column($column1, $column2, $after_column, $table, $db) {
    global $options;
    
    $modify = array();
    
    if ($column1->COLUMN_TYPE != $column2->COLUMN_TYPE) 
        $modify['type'] = " $column1->COLUMN_TYPE";
        
    if ($column1->COLLATION_NAME != $column2->COLLATION_NAME) 
        $modify['collation'] = " COLLATION $column1->COLLATION_NAME";
    
    if ($column1->IS_NULLABLE != $column2->IS_NULLABLE)
        $modify['null'] = strcasecmp($column1->IS_NULLABLE, 'NO') == 0 ? ' NOT NULL' : ' NULL';
    
    if ($column1->COLUMN_DEFAULT != $column2->COLUMN_DEFAULT) {
        // FALSE is an special value that indicates we should DROP this column's default value,
        // causing MySQL to assign it the "default default".
        $modify['default'] = isset($column1->COLUMN_DEFAULT) ? ' DEFAULT ' . format_default_value($column1->COLUMN_DEFAULT, $db) : FALSE;
    }
    
    if ($column1->EXTRA != $column2->EXTRA)
        $modify['extra'] = " $column1->EXTRA";
    
    if ($column1->COLUMN_COMMENT != $column2->COLUMN_COMMENT)
        $modify['comment'] = " COMMENT '$column1->COLUMN_COMMENT'";
        
    if ($column1->ORDINAL_POSITION != $column2->ORDINAL_POSITION)
        $modify['position'] = $after_column ? " AFTER `$after_column`" : ' FIRST';
    
    if ($modify) {
        $sql = "ALTER TABLE `$table` MODIFY `$column1->COLUMN_NAME`";
        
        $sql .= isset($modify['type']) ? $modify['type'] : " $column2->COLUMN_TYPE";
        
        if (isset($modify['collation'])) 
            $sql .= $modify['collation'];
        
        if (isset($modify['null'])) 
            $sql .= $modify['null'];
        else
            $sql .= strcasecmp($column2->IS_NULLABLE, 'NO') == 0 ? ' NOT NULL' : ' NULL';
            
        if (isset($modify['default']) && $modify['default'] !== FALSE) {
            $sql .= $modify['default'];
        } elseif (isset($column2->COLUMN_DEFAULT)) 
            $sql .= ' DEFAULT ' . format_default_value($column2->COLUMN_DEFAULT, $db);
            
        if (isset($modify['extra'])) 
            $sql .= $modify['extra'];
        elseif ($column2->EXTRA != '')
            $sql .= " $column2->EXTRA";
        
        if (isset($modify['comment'])) 
            $sql .= $modify['comment'];
        elseif ($column2->COLUMN_COMMENT != '')
            $sql .= " COMMENT '$column2->COLUMN_COMMENT'";
        
        if (isset($modify['position']))
            $sql .= $modify['position'];
                
        $sql .= ";\n";
        
        fputs($options->ofh, $sql);
    }
}

function alter_table_drop_columns($columns1, $columns2) {
    global $options;
    
    $sql = '';
    $columns = array_diff_key($columns2, $columns1);
    foreach ($columns as $c) {
        $sql .= "ALTER TABLE `$t` DROP COLUMN `$c->COLUMN_NAME`;\n";
    }
    
    fputs($options->ofh, $sql);
}

function alter_tables_columns($db1, $db2) {
    global $options;
    
    $tables1 = list_tables($db1);
    $tables2 = list_tables($db2);

    $tables = array_intersect(array_keys($tables1), array_keys($tables2));
    foreach ($tables as $t) {
        $columns1 = list_columns($t, $db1);
        $columns2 = list_columns($t, $db2);
        $columns_index = array_keys($columns1);
        
        foreach ($columns1 as $c1) {
            $after_column = $c1->ORDINAL_POSITION == 1 ? NULL : $columns_index[$c1->ORDINAL_POSITION - 2];
            
            if (!isset($columns2[$c1->COLUMN_NAME])) 
                alter_table_add_column($c1, $after_column, $t, $db2);
            else
                alter_table_modify_column($c1, $columns2[$c1->COLUMN_NAME], $after_column, $t, $db2);
        }

        if ($options->drop_columns)
            alter_table_drop_columns($columns1, $columns2);
    }
}

function alter_tables($tables1, $tables2) {
    global $options;
    
    $sql = '';
    $table_names = array_intersect(array_keys($tables2), array_keys($tables1));
    foreach ($table_names as $t) {
        $t1 = $tables1[$t];
        $t2 = $tables2[$t];
        
        if ($t1->ENGINE != $t2->ENGINE)
            $sql .= "ALTER TABLE `$t` ENGINE=$t1->ENGINE;\n";
        
        if ($t1->TABLE_COLLATION != $t2->TABLE_COLLATION)
            $sql .= "ALTER TABLE `$t` COLLATE=$t1->TABLE_COLLATION;\n";
        
        if ($t1->ROW_FORMAT != $t2->ROW_FORMAT)
            $sql .= "ALTER TABLE `$t` ROW_FORMAT=$t1->ROW_FORMAT;\n";
            
        if ($t1->CHECKSUM != $t2->CHECKSUM)
            $sql .= "ALTER TABLE `$t` CHECKSUM=$t1->CHECKSUM;\n";
            
        /*if ($t1->TABLE_COMMENT != $t2->TABLE_COMMENT)
            $sql .= "ALTER TABLE `$t` COMMENT='$t1->TABLE_COMMENT';\n";
        */
        
        if ($sql)
            $sql .= "\n";
    }
    
    fputs($options->ofh, $sql);
}

function are_indexes_eq($index1, $index2) {
    if ($index1->non_unique != $index2->non_unique)
        return FALSE;
    if (count($index1->columns) != count($index2->columns))
        return FALSE;
    
    foreach ($index1->columns as $name => $column1) {
        if (!isset($index2->columns[$name]))
            return FALSE;
        if ($column1->seq != $index2->columns[$name]->seq)
            return FALSE;
        if ($column1->sub_part != $index2->columns[$name]->sub_part)
            return FALSE;
        if ($column1->type != $index2->columns[$name]->type)
            return FALSE;
        /*if ($column1->collation != $index2->columns[$name]->collation)
            return FALSE;*/
    }
    
    return TRUE;
}

function build_drop_index_sql($index) {
    return $index->key_name == 'PRIMARY' ? 
        "ALTER TABLE `$index->table` DROP PRIMARY KEY;" : 
        "ALTER TABLE `$index->table` DROP INDEX $index->key_name;";
}

function build_create_index_sql($index) {
    $column_list = array();
    foreach ($index->columns as $name => $column) {
        $column_list[] = $name . ($column->sub_part ? "($column->sub_part)" : '');
    }
    $column_list = '(' . implode(',', $column_list) . ')';
    
    if ($index->key_name == 'PRIMARY') 
        $result = "ALTER TABLE `$index->table` ADD PRIMARY KEY $column_list;";
    else {
        if ($index->type == 'FULLTEXT') 
            $index_type = ' FULLTEXT';
        elseif (!$index->non_unique) 
            $index_type = ' UNIQUE';
        else
            $index_type = '';
            
        $result = "CREATE$index_type INDEX $index->key_name ON `$index->table` $column_list;";
    } 
        
    return $result;
}

function alter_table_add_indexes($idx1, $idx2) {
    global $options;
    
    $indexes = array_diff_key($idx1, $idx2);
    $sql = '';
    foreach ($indexes as $index_name => $index)
        $sql .= build_create_index_sql($index) . "\n";
    
    fputs($options->ofh, $sql);
}

function alter_table_drop_indexes($idx1, $idx2) {
    global $options;
    
    $indexes = array_diff_key($idx2, $idx1);
    $sql = '';
    foreach ($indexes as $index_name => $index)
        $sql .= build_drop_index_sql($index) . "\n";
    
    fputs($options->ofh, $sql);
}

function alter_table_alter_indexes($idx1, $idx2) {
    global $options;
    
    $sql = '';
    $indexes = array_intersect_key($idx1, $idx2);
    foreach ($indexes as $index_name => $index)
        if (!are_indexes_eq($index, $idx2[$index_name])) {
            $sql .= build_drop_index_sql($idx2[$index_name]) . "\n";
            $sql .= build_create_index_sql($index) . "\n";
        }
        
    fputs($options->ofh, $sql);
}

function process_database($db1, $db2) {
    global $options;
    
    $sql = "USE `$db2->database`;\n";
    
    if ($db1->charset != $db2->charset) 
        $sql .= "ALTER DATABASE `$db2->database` CHARACTER SET=$db1->charset;\n";
    
    if ($db1->collation != $db2->collation) 
        $sql .= "ALTER DATABASE `$db2->database` COLLATE=$db1->collation;\n";
    
    $sql .= "\n";
        
    fputs($options->ofh, $sql);
}

function process_indexes($tables1, $tables2, $db1, $db2) {
    $tables = array_intersect_key($tables1, $tables2);
    foreach (array_keys($tables) as $t) {
        $idx1 = list_indexes($t, $db1);
        $idx2 = list_indexes($t, $db2);
        
        alter_table_drop_indexes($idx1, $idx2);
        alter_table_add_indexes($idx1, $idx2);
        alter_table_alter_indexes($idx1, $idx2);
    }
}

function process_tables($db1, $db2) {
    global $options;
    
    $tables1 = list_tables($db1);
    $tables2 = list_tables($db2);

    create_tables($db1, $tables1, $tables2);
    
    if ($options->drop_tables)
        drop_tables($tables1, $tables2);       
        
    alter_tables($tables1, $tables2);
    alter_tables_columns($db1, $db2);
    
    process_indexes($tables1, $tables2, $db1, $db2);
}

function usage() {
    echo <<<MSG

THIS IS AN ALPHA VERSION. DO NOT USE ON PRODUCTION!
    
Usage:    
  php mysqldiff.php <options>

Options:
  --database1 <database-name>   Name of source db.
  --host1 <hostname>            Server hosting source db.
  --user1 <username>            Username for connectiong to source db.
  --pwd1 <pwd>                  Password for connectiong to source db.
  
  --database2 <database-name>   Name of destination db.
  --host2 <hostname>            Server hosting destination db.
  --user2 <username>            Username for connectiong to destination db.
  --pwd2 <pwd>                  Password for connectiong to destination db.
  
  --drop-tables                 Whether to generate DROP TABLE statements
                                for tables present in destination but not 
                                on source database.
                                Note this can happen when you simply rename
                                a table. Default is NOT TO DROP.

  --drop-columns                Whether to generate ALTER TABLE...DROP COLUMN 
                                statements for columns present in destination 
                                but not on source database. 
                                Note this can happen when you simply rename
                                a column. Default is NOT TO DROP.
                                
  --output-file <filename>      Filename to save the generated MySQL script.
                                Default is to write to SDTOUT.
                                
  --overwrite                   Overwrite the output file without asking for 
                                confirmation. Default is to ask.

If source and destination databases share some connection data,
you can specify them using:

  --database <database-name>    Name of both dbs.
  --host <hostname>             Server hosting both dbs.
  --user <username>             Username for connectiong to both dbs.
  --pwd <pwd>                   Password for connectiong to both dbs.
  
The default hostname is "localhhost".
Both passwords are empty by default.

MSG;

    exit(0);
}

function error($msg) {
    fputs(STDERR, "mysqldiff: $msg\n");
    exit(1);
}

function prompt($msg) {
    echo $msg;
    return trim(fgets(STDIN));
}

$options = (object)array(
    'drop_columns' => FALSE, 
    'drop_tables' => FALSE,
    'new_table_data' => FALSE,
    'db1' => (object)array(
        'host' => 'localhost', 
        'pwd' => NULL
    ),
    'db2' => (object)array(
        'host' => 'localhost', 
        'pwd' => NULL
    ),
    'output_file' => NULL,
    'ofh' => STDOUT, // output file handle
);

$db1 = &$options->db1;
$db2 = &$options->db2;

if ($argc == 1)
    usage();

// Parse command line arguments.
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--host1': 
            $db1->host = $argv[++$i];
            break;
        case '--database1':
            $db1->database = $argv[++$i];
            break;
        case '--user1':
            $db1->user = $argv[++$i];
            break;
        case '--pwd1':
            $db1->pwd = $argv[++$i];
            break;
        case '--host2':
            $db2->host = $argv[++$i];
            break;
        case '--database2':
            $db2->database = $argv[++$i];
            break;
        case '--user2':
            $db2->user = $argv[++$i];
            break;
        case '--pwd2':
            $db2->pwd = $argv[++$i];
            break;
        case '--host':
            $db1->host = $db2->host = $argv[++$i];
            break;
        case '--database':
            $db1->database = $db2->database = $argv[++$i];
            break;
        case '--user':
            $db1->user = $db2->user = $argv[++$i];
            break;
        case '--pwd':
            $db1->pwd = $db2->pwd = $argv[++$i];
            break;
        case '--drop-columns':
            $options->drop_columns = TRUE;
            break;
        case '--drop-tables':
            $options->drop_tables = TRUE;
            break;
        case '--new-table-data':
            $options->new_table_data = TRUE;
            break;
        case '--output-file':
            $options->output_file = $argv[++$i];
            break;
        case '--overwrite':
            $options->overwrite = TRUE;
            break;
        case '--help':
        case '-h':
            usage();
        default:
            error("don't know what to do with \"{$argv[$i]}\"");
    }
}

/*
$db1->database = 'diskstoragecatalog';
$db2->database = 'diskstoragecatalog2';
$db1->user = $db2->user = 'root';
$options->output_dir = 'c:/temp/perico';
$options->overwrite = TRUE;
*/

if (!$db1->database)
    error("source database must be specified with --database1 or --database");

if (!$db2->database)
    error("destination database must be specified with --database2 or --database");
    
if ($db1->host == $db2->host && $db1->database == $db2->database)
    error("databases names must be different if they reside on the same host");

if ($options->output_file) {
    if (file_exists($options->output_file) && !$options->overwrite) {
        if (prompt("Output file $options->output_file exists. Overwrite it (y/n)? ") != 'y') 
            exit(0);
    }
    $options->ofh = @fopen($options->output_file, 'w') or error("error creating output file $options->output_file");
}

$db1->link = @mysql_connect($db1->host, $db1->user, $db1->pwd, TRUE) or error(mysql_error());
mysql_selectdb($db1->database, $db1->link) or error(mysql_error($db1->link));

$db2->link = @mysql_connect($db2->host, $db2->user, $db2->pwd, TRUE) or error(mysql_error());
mysql_selectdb($db2->database, $db2->link)  or error(mysql_error($db2->link));

populate_schemata_info($db1);
populate_schemata_info($db2);

process_database($db1, $db2);
process_tables($db1, $db2);

?>
