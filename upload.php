<?php
/**
 * ################################################################################
 * MyChunkUploader
 * 
 * Copyright 2016 Eugen Mihailescu <eugenmihailescux@gmail.com>
 * 
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * ################################################################################
 * 
 * Short description:
 * URL: https://github.com/eugenmihailescu/my-chunk-uploader
 * 
 * Git revision information:
 * 
 * @version : 0.2.3-20 $
 * @commit  : 12457b6aeee63b6f6ae447c52e56b029cebe3417 $
 * @author  : eugenmihailescu <eugenmihailescux@gmail.com> $
 * @date    : Tue Mar 15 16:16:30 2016 +0100 $
 * @file    : upload.php $
 * 
 * @id      : upload.php | Tue Mar 15 16:16:30 2016 +0100 | eugenmihailescu <eugenmihailescux@gmail.com> $
*/

namespace MyChunkUploader;

require_once 'MyChunkUploader.php';
$uploader = new MyChunkUploader();
$uploader->run();
?>