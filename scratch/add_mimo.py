import json
import os

config_path = r"C:\Users\aditia\.config\opencode\opencode.json"

with open(config_path, "r", encoding="utf-8-sig") as f:
    data = json.load(f)

# Define the new provider
xiaomimimo_provider = {
    "npm": "@ai-sdk/openai-compatible",
    "options": {
        "baseURL": "https://token-plan-sgp.xiaomimimo.com/v1",
        "apiKey": "tp-skrnl926i0yv5vrg2bxfu1h7k04rq3btr26wtbi42ufmux0x",
        "name": "xiaomimimo"
    },
    "models": {
        "mimo-v2.5": {
            "name": "XiaomiMiMo mimo-v2.5",
            "limit": {
                "context": 128000,
                "output": 8192
            },
            "modalities": {
                "input": ["text"],
                "output": ["text"]
            }
        },
        "mimo-v2.5-pro": {
            "name": "XiaomiMiMo mimo-v2.5-pro",
            "limit": {
                "context": 128000,
                "output": 8192
            },
            "modalities": {
                "input": ["text"],
                "output": ["text"]
            }
        }
    }
}

data["provider"]["xiaomimimo"] = xiaomimimo_provider

with open(config_path, "w", encoding="utf-8-sig") as f:
    json.dump(data, f, indent=4, ensure_ascii=False)

print("Successfully added xiaomimimo provider to opencode.json")
