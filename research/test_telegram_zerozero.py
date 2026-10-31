import sys,json,tempfile,unittest
from pathlib import Path
from datetime import datetime,timezone,timedelta
sys.path.insert(0,str(Path(__file__).resolve().parent.parent/'msports-parser'))
import telegram_notify as notify

class NotificationTests(unittest.TestCase):
    def test_health_transitions(self):
        state,event=notify.health_transition(None,'ok',0,'scraper');self.assertIsNone(event)
        state,event=notify.health_transition(state,'offline',5,'scraper');self.assertIsNone(event)
        state,event=notify.health_transition(state,'offline',20,'scraper');self.assertIsNone(event)
        state,event=notify.health_transition(state,'offline',35,'scraper');self.assertEqual(event,'outage')
        state,event=notify.health_transition(state,'offline',100,'scraper');self.assertIsNone(event)
        state,event=notify.health_transition(state,'ok',105,'scraper');self.assertEqual(event,'recovered')
        state,event=notify.health_transition(state,'ok',110,'scraper');self.assertIsNone(event)
        state,event=notify.health_transition(None,'denied',0,'api');self.assertEqual(event,'outage')
        state,event=notify.health_transition(state,'denied',60,'api');self.assertIsNone(event)
        state,event=notify.health_transition(state,'ok',120,'api');self.assertEqual(event,'recovered')
    def test_transient_failure_no_false_recovery(self):
        state,event=notify.health_transition(None,'stale',0,'scraper');self.assertIsNone(event)
        state,event=notify.health_transition(state,'ok',5,'scraper');self.assertIsNone(event)
    def test_threshold_and_no_repeat(self):
        counts={'15|2026-10-19':{'Italy':2},'16|2026-10-18':{'France':2},'20|2026-10-19':{'Brazil':2}}
        first=notify.notifications(counts,{})
        self.assertEqual(len(first),0)
        self.assertEqual(notify.notifications({'16|2026-10-18':{'France':3}},{}),[])
        self.assertEqual(notify.notifications({'16|2026-10-18':{'France':4}},counts),[('16|2026-10-18','France',4)])
        self.assertEqual(notify.notifications(counts,counts),[])
        for count in [3,4]:
            self.assertEqual(notify.notifications({'15|2026-10-19':{'Italy':count}},{}),[])
        changed={k:dict(v) for k,v in counts.items()};changed['15|2026-10-19']['Italy']=5
        self.assertEqual(notify.notifications(changed,counts),[('15|2026-10-19','Italy',5)])
        changed20={k:dict(v) for k,v in counts.items()};changed20['20|2026-10-19']['Brazil']=3
        self.assertEqual(notify.notifications(changed20,counts),[('20|2026-10-19','Brazil',3)])
        self.assertEqual(len(notify.notifications({'15|2026-10-20':{'Italy':5}},changed)),1)
    def test_finished_only_and_session(self):
        with tempfile.TemporaryDirectory() as folder:
            rows=[]
            def row(id,league,time,score='0:0_0:0',finished='Y'):
                return dict(no_partai=id,events=league,match_date=time,fs_home=score,st_fs=finished,st_postponed='N',home_name='France (V)',away_name='Italy (V)')
            rows=[row('a','10031403','2026-10-19T00:45:00+08:00'),row('b','10031403','2026-10-19T00:46:00+08:00'),row('c','10026214','2026-10-19T00:00:00+08:00'),row('d','10026214','2026-10-19T00:15:00+08:00',finished='N'),row('e','10026214','2026-10-19T00:30:00+08:00',score='0:0_1:0')]
            Path(folder,'msports-2026-10-19.json').write_text(json.dumps({'data':rows}))
            Path(folder,'msports-2026-10-18.json').write_text(json.dumps({'data':[rows[0]]}))
            counts,fresh=notify.collect(datetime(2026,10,19,9,tzinfo=timezone(timedelta(hours=8))),folder)
            self.assertTrue(fresh)
            self.assertEqual(counts['16|2026-10-18']['France'],1)
            self.assertEqual(counts['15|2026-10-19']['France'],1)
            self.assertEqual(counts['20|2026-10-19'],{})
if __name__=='__main__':unittest.main()
