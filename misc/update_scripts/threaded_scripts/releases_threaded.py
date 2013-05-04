#!/usr/bin/python
# -*- coding: utf-8 -*-

import sys, os, time
import threading, Queue
import MySQLdb as mdb
import subprocess
import string
import re

pathname = os.path.abspath(os.path.dirname(sys.argv[0]))

def readConfig():
		Configfile = pathname+"/../../../www/config.php"
		file = open( Configfile, "r")

		# Match a config line
		m = re.compile('^define\(\'([A-Z_]+)\', \'?(.*?)\'?\);$', re.I)

		# The config object
		config = {}
		config['DB_PORT']=3306
		for line in file.readlines():
				match = m.search( line )
				if match:
						value = match.group(2)

						# filter boolean
						if "true" is value:
								value = True
						elif "false" is value:
								value = False

						# Add to the config
						#config[ match.group(1).lower() ] = value	   # Lower case example
						config[ match.group(1) ] = value
		return config

# Test
config = readConfig()

con = None
# The MYSQL connection.
con = mdb.connect(config['DB_HOST'], config['DB_USER'], config['DB_PASSWORD'], config['DB_NAME'], int(config['DB_PORT']));

# The group names.
cur = con.cursor()
cur.execute("select value from site where setting = 'releasethreads'");
run_threads = cur.fetchone();
cur.execute("select value from tmux where setting = 'RELEASES_THREADED'");
run_threads_alt = cur.fetchone();

if run_threads_alt[0] == "TRUE":
	cur.execute("SELECT name from groups where active = 1 ORDER BY first_record_postdate DESC")
	datas = cur.fetchall()
	type_work = "Groups"
else:
	datas = ['1','2','3','4','5','6','7','8']
	type_work = "Stage"

class WorkerThread(threading.Thread):
	def __init__(self, threadID, result_q):
		super(WorkerThread, self).__init__()
		self.threadID = threadID
		self.result_q = result_q
		self.stoprequest = threading.Event()

	def run(self):
		while not self.stoprequest.isSet():
			try:
				dirname = self.threadID.get(True, 0.05)
				#print '\n%s: %s %s started.' % (self.name, type_work, dirname)
				if run_threads_alt[0] == "TRUE":
					subprocess.call(["php", pathname+"/update_releases.php", ""+dirname])
				else:
					subprocess.call(["php", pathname+"/update_releases_threaded.php", ""+dirname])
				self.result_q.put((self.name, dirname))
			except Queue.Empty:
				continue

	def join(self, timeout=None):
		self.stoprequest.set()
		super(WorkerThread, self).join(timeout)

def main(args):
	# Create a single input and a single output queue for all threads.
	threadID = Queue.Queue()
	result_q = Queue.Queue()

	# Create the "thread pool"
	pool = [WorkerThread(threadID=threadID, result_q=result_q) for i in range(int(run_threads[0]))]

	# Start all threads
	for thread in pool:
		thread.start()

	# Give the workers some work to do
	work_count = 0
	for gnames in datas:
		work_count += 1
		threadID.put(gnames[0])

	print 'Assigned %s %s to workers' % (work_count, type_work)

	while work_count > 0:
		# Blocking 'get' from a Queue.
		result = result_q.get()
		#print '\n%s: %s %s finished.' % (result[0], type_work, result[1])
		work_count -= 1

	# Ask threads to die and wait for them to do it
	for thread in pool:
		thread.join()

if __name__ == '__main__':
	import sys
	main(sys.argv[1:])
