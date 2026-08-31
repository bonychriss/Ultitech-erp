import { useState } from "react";
import { Button } from "./ui/button";
import { Input } from "./ui/input";
import { Label } from "./ui/label";
import { Textarea } from "./ui/textarea";
import { Switch } from "./ui/switch";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "./ui/dialog";
import { Calendar } from "./ui/calendar";
import { Popover, PopoverContent, PopoverTrigger } from "./ui/popover";
import { Badge } from "./ui/badge";
import { Calendar as CalendarIcon, Clock, Bell, X, Plus } from "lucide-react";
import { format } from "date-fns";
import { Task, Reminder } from "./TaskItem";

interface AddTaskDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSave: (task: Omit<Task, 'id' | 'createdAt'>) => void;
  editingTask?: Task | null;
}

const REMINDER_OPTIONS = [
  { minutesBefore: 5, label: "5 minutes before" },
  { minutesBefore: 10, label: "10 minutes before" },
  { minutesBefore: 30, label: "30 minutes before" },
  { minutesBefore: 60, label: "1 hour before" },
  { minutesBefore: 1440, label: "1 day before" },
];

export function AddTaskDialog({ open, onOpenChange, onSave, editingTask }: AddTaskDialogProps) {
  const [title, setTitle] = useState(editingTask?.title || "");
  const [description, setDescription] = useState(editingTask?.description || "");
  const [dueDate, setDueDate] = useState<Date | undefined>(editingTask?.dueDate);
  const [dueTime, setDueTime] = useState(
    editingTask?.dueDate ? format(editingTask.dueDate, "HH:mm") : "09:00"
  );
  const [enableReminders, setEnableReminders] = useState(
    editingTask ? editingTask.reminders.length > 0 : false
  );
  const [reminders, setReminders] = useState<Reminder[]>(editingTask?.reminders || []);
  const [showReminderMenu, setShowReminderMenu] = useState(false);

  const handleSave = () => {
    if (!title.trim()) return;

    let finalDueDate: Date | undefined = undefined;
    if (dueDate) {
      const [hours, minutes] = dueTime.split(":").map(Number);
      finalDueDate = new Date(dueDate);
      finalDueDate.setHours(hours, minutes, 0, 0);
    }

    onSave({
      title: title.trim(),
      description: description.trim() || undefined,
      dueDate: finalDueDate,
      reminders: enableReminders ? reminders : [],
      completed: editingTask?.completed || false,
    });

    // Reset form
    setTitle("");
    setDescription("");
    setDueDate(undefined);
    setDueTime("09:00");
    setEnableReminders(false);
    setReminders([]);
    onOpenChange(false);
  };

  const addReminder = (option: { minutesBefore: number; label: string }) => {
    if (reminders.some(r => r.minutesBefore === option.minutesBefore)) return;
    
    setReminders([
      ...reminders,
      {
        id: Date.now().toString(),
        minutesBefore: option.minutesBefore,
        label: option.label,
      },
    ]);
    setShowReminderMenu(false);
  };

  const removeReminder = (id: string) => {
    setReminders(reminders.filter(r => r.id !== id));
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px] rounded-3xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {editingTask ? "Edit Task" : "Add New Task"}
          </DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <div className="space-y-2">
            <Label htmlFor="title">Title *</Label>
            <Input
              id="title"
              placeholder="Enter task title"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className="rounded-xl"
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="description">Description</Label>
            <Textarea
              id="description"
              placeholder="Add details about your task (optional)"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              className="rounded-xl min-h-[80px] resize-none"
            />
          </div>

          <div className="space-y-2">
            <Label>Due Date & Time</Label>
            <div className="flex gap-2">
              <Popover>
                <PopoverTrigger asChild>
                  <Button
                    variant="outline"
                    className="flex-1 justify-start text-left rounded-xl"
                  >
                    <CalendarIcon className="mr-2 h-4 w-4" />
                    {dueDate ? format(dueDate, "MMM d, yyyy") : "Pick a date"}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-auto p-0 rounded-2xl" align="start">
                  <Calendar
                    mode="single"
                    selected={dueDate}
                    onSelect={setDueDate}
                    initialFocus
                  />
                </PopoverContent>
              </Popover>

              <div className="relative">
                <Clock className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                <Input
                  type="time"
                  value={dueTime}
                  onChange={(e) => setDueTime(e.target.value)}
                  className="pl-10 rounded-xl w-[130px]"
                />
              </div>
            </div>
          </div>

          <div className="space-y-3 pt-2">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Reminders</Label>
                <p className="text-xs text-gray-500">
                  Get notified before the due date
                </p>
              </div>
              <Switch
                checked={enableReminders}
                onCheckedChange={setEnableReminders}
              />
            </div>

            {enableReminders && (
              <div className="space-y-2 pl-2">
                <div className="flex flex-wrap gap-2">
                  {reminders.map((reminder) => (
                    <Badge
                      key={reminder.id}
                      variant="secondary"
                      className="pl-3 pr-1 py-1.5 rounded-full bg-purple-100 text-purple-700"
                    >
                      <Bell className="h-3 w-3 mr-1 inline" />
                      {reminder.label}
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-5 w-5 p-0 ml-2 rounded-full hover:bg-purple-200"
                        onClick={() => removeReminder(reminder.id)}
                      >
                        <X className="h-3 w-3" />
                      </Button>
                    </Badge>
                  ))}
                </div>

                <Popover open={showReminderMenu} onOpenChange={setShowReminderMenu}>
                  <PopoverTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      className="rounded-full"
                    >
                      <Plus className="h-4 w-4 mr-1" />
                      Add Reminder
                    </Button>
                  </PopoverTrigger>
                  <PopoverContent className="w-56 p-2 rounded-xl" align="start">
                    <div className="space-y-1">
                      {REMINDER_OPTIONS.map((option) => (
                        <Button
                          key={option.minutesBefore}
                          variant="ghost"
                          className="w-full justify-start rounded-lg text-sm"
                          onClick={() => addReminder(option)}
                          disabled={reminders.some(r => r.minutesBefore === option.minutesBefore)}
                        >
                          <Bell className="h-4 w-4 mr-2" />
                          {option.label}
                        </Button>
                      ))}
                    </div>
                  </PopoverContent>
                </Popover>
              </div>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            className="rounded-xl"
          >
            Cancel
          </Button>
          <Button
            onClick={handleSave}
            disabled={!title.trim()}
            className="rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700"
          >
            {editingTask ? "Update Task" : "Add Task"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
