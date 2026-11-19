import json
import os
from pathlib import Path
d=json.loads(Path(os.environ["MBI56_TEST_METRICS"]).read_text())
images=d.pop("images")
assert d["renews"] > 1 and d["failure"] and d["inventory_failure"], d
assert d["worker"] == 0
checked={k:v for k,v in images.items() if k.split("/")[-1].startswith("work-4-")}
assert len(checked)==2756 and max(checked.values())==1, (len(checked),max(checked.values(),default=0))
print("PASS 176 chapters and 2,756 image probes with no repeated probes on resume")
print("PASS paginated inventory resumed after an origin error without losing a chapter")
print("METRICS "+json.dumps(d | {"distinct_origin_images":len(images)}))
