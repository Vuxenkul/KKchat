extends Resource
class_name Task

@export var id: int = 0
@export var name: String = ""
@export var difficulty_xp: int = 5
@export var schedule_type: String = "daily"
@export var times_per_week_target: int = 0
@export var group: String = ""
@export var completions: Array[String] = []

func is_completed_on_date(date_string: String) -> bool:
    return completions.has(date_string)

func to_dict() -> Dictionary:
    return {
        "id": id,
        "name": name,
        "difficulty_xp": difficulty_xp,
        "schedule_type": schedule_type,
        "times_per_week_target": times_per_week_target,
        "group": group,
        "completions": completions.duplicate()
    }

static func from_dict(data: Dictionary) -> Task:
    var task := Task.new()
    task.id = data.get("id", 0)
    task.name = data.get("name", "")
    task.difficulty_xp = data.get("difficulty_xp", 5)
    task.schedule_type = data.get("schedule_type", "daily")
    task.times_per_week_target = data.get("times_per_week_target", 0)
    task.group = data.get("group", "")
    task.completions = data.get("completions", [])
    return task
