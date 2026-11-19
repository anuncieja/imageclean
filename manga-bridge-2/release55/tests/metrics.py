import json
import os
from pathlib import Path
d=json.loads(Path(os.environ["MBI55_TEST_METRICS"]).read_text())
images=d.pop("images")
assert d["renews"] > 1 and d["failure"], d
assert d["worker"] == 0
checked={k:v for k,v in images.items() if k.split("/")[-1].startswith("work-4-")}
assert len(checked)==361 and max(checked.values())==1, (len(checked),max(checked.values(),default=0))
print("PASS 120-chapter work resumed without repeating any of its 361 image probes")
print("METRICS "+json.dumps(d | {"distinct_origin_images":len(images)}))
