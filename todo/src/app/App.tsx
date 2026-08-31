import { useState, useEffect } from "react";
import { TaskItem, Task } from "./components/TaskItem";
import { AddTaskDialog } from "./components/AddTaskDialog";
import { QuickAddTask } from "./components/QuickAddTask";
import { Button } from "./components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "./components/ui/tabs";
import { Plus, CheckCircle2, Calendar, Clock } from "lucide-react";
import { motion, AnimatePresence } from "motion/react";
import { isToday, isFuture, startOfDay } from "date-fns";
import { toast, Toaster } from "sonner";
import confetti from "canvas-confetti";

function App() {
  const [tasks, setTasks] = useState<Task[]>([]);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingTask, setEditingTask] = useState<Task | null>(null);
  const [activeTab, setActiveTab] = useState("today");

  // Load tasks from localStorage on mount
  useEffect(() => {
    const stored = localStorage.getItem("tasks");
    if (stored) {
      try {
        const parsed = JSON.parse(stored);
        const tasksWithDates = parsed.map((task: Task) => ({
          ...task,
          dueDate: task.dueDate ? new Date(task.dueDate) : undefined,
          createdAt: new Date(task.createdAt),
        }));
        setTasks(tasksWithDates);
      } catch (e) {
        console.error("Failed to parse tasks from localStorage", e);
      }
    }
  }, []);

  // Save tasks to localStorage whenever they change
  useEffect(() => {
    localStorage.setItem("tasks", JSON.stringify(tasks));
  }, [tasks]);

  const handleQuickAdd = (title: string) => {
    const newTask: Task = {
      id: Date.now().toString(),
      title,
      completed: false,
      reminders: [],
      createdAt: new Date(),
    };
    setTasks([newTask, ...tasks]);
    toast.success("Task added!");
  };

  const handleSaveTask = (taskData: Omit<Task, "id" | "createdAt">) => {
    if (editingTask) {
      setTasks(
        tasks.map((t) =>
          t.id === editingTask.id
            ? { ...taskData, id: editingTask.id, createdAt: editingTask.createdAt }
            : t
        )
      );
      toast.success("Task updated!");
      setEditingTask(null);
    } else {
      const newTask: Task = {
        ...taskData,
        id: Date.now().toString(),
        createdAt: new Date(),
      };
      setTasks([newTask, ...tasks]);
      toast.success("Task created!");
    }
  };

  const handleToggleComplete = (id: string) => {
    setTasks(
      tasks.map((task) => {
        if (task.id === id) {
          const newCompleted = !task.completed;
          if (newCompleted) {
            // Celebration animation when task is completed
            confetti({
              particleCount: 50,
              spread: 60,
              origin: { y: 0.6 },
              colors: ['#3b82f6', '#8b5cf6', '#ec4899'],
            });
            toast.success("Task completed! 🎉");
          }
          return { ...task, completed: newCompleted };
        }
        return task;
      })
    );
  };

  const handleEditTask = (task: Task) => {
    setEditingTask(task);
    setDialogOpen(true);
  };

  const handleDeleteTask = (id: string) => {
    setTasks(tasks.filter((task) => task.id !== id));
    toast.success("Task deleted");
  };

  const handleOpenDialog = () => {
    setEditingTask(null);
    setDialogOpen(true);
  };

  // Filter tasks
  const todayTasks = tasks.filter(
    (task) => !task.completed && (!task.dueDate || isToday(task.dueDate))
  );
  
  const upcomingTasks = tasks.filter(
    (task) =>
      !task.completed &&
      task.dueDate &&
      !isToday(task.dueDate) &&
      isFuture(startOfDay(task.dueDate))
  );

  const completedTasks = tasks.filter((task) => task.completed);

  const getTasksForTab = (tab: string) => {
    switch (tab) {
      case "today":
        return todayTasks;
      case "upcoming":
        return upcomingTasks;
      case "completed":
        return completedTasks;
      default:
        return [];
    }
  };

  const renderTaskList = (taskList: Task[], emptyMessage: string) => {
    if (taskList.length === 0) {
      return (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          className="text-center py-12"
        >
          <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 mb-4">
            <CheckCircle2 className="h-8 w-8 text-gray-400" />
          </div>
          <p className="text-gray-500">{emptyMessage}</p>
        </motion.div>
      );
    }

    return (
      <AnimatePresence mode="popLayout">
        <div className="space-y-3">
          {taskList.map((task) => (
            <TaskItem
              key={task.id}
              task={task}
              onToggleComplete={handleToggleComplete}
              onEdit={handleEditTask}
              onDelete={handleDeleteTask}
            />
          ))}
        </div>
      </AnimatePresence>
    );
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 via-purple-50 to-pink-50">
      <div className="max-w-md mx-auto px-4 py-6 pb-24">
        {/* Header */}
        <motion.div
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
          className="mb-8"
        >
          <h1 className="text-3xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent mb-2">
            My Tasks
          </h1>
          <p className="text-gray-600">
            {new Date().toLocaleDateString("en-US", {
              weekday: "long",
              month: "long",
              day: "numeric",
            })}
          </p>
        </motion.div>

        {/* Quick Add */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.1 }}
          className="mb-6"
        >
          <QuickAddTask onAdd={handleQuickAdd} />
        </motion.div>

        {/* Tabs */}
        <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
          <TabsList className="grid w-full grid-cols-3 mb-6 bg-white/70 backdrop-blur-sm rounded-2xl p-1">
            <TabsTrigger
              value="today"
              className="rounded-xl data-[state=active]:bg-gradient-to-r data-[state=active]:from-blue-500 data-[state=active]:to-purple-600 data-[state=active]:text-white"
            >
              <Clock className="h-4 w-4 mr-2" />
              Today
              {todayTasks.length > 0 && (
                <span className="ml-2 px-2 py-0.5 rounded-full bg-white/20 text-xs">
                  {todayTasks.length}
                </span>
              )}
            </TabsTrigger>
            <TabsTrigger
              value="upcoming"
              className="rounded-xl data-[state=active]:bg-gradient-to-r data-[state=active]:from-blue-500 data-[state=active]:to-purple-600 data-[state=active]:text-white"
            >
              <Calendar className="h-4 w-4 mr-2" />
              Upcoming
              {upcomingTasks.length > 0 && (
                <span className="ml-2 px-2 py-0.5 rounded-full bg-white/20 text-xs">
                  {upcomingTasks.length}
                </span>
              )}
            </TabsTrigger>
            <TabsTrigger
              value="completed"
              className="rounded-xl data-[state=active]:bg-gradient-to-r data-[state=active]:from-blue-500 data-[state=active]:to-purple-600 data-[state=active]:text-white"
            >
              <CheckCircle2 className="h-4 w-4 mr-2" />
              Done
              {completedTasks.length > 0 && (
                <span className="ml-2 px-2 py-0.5 rounded-full bg-white/20 text-xs">
                  {completedTasks.length}
                </span>
              )}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="today" className="mt-0">
            {renderTaskList(todayTasks, "No tasks for today. Enjoy your day! ☀️")}
          </TabsContent>

          <TabsContent value="upcoming" className="mt-0">
            {renderTaskList(upcomingTasks, "No upcoming tasks scheduled 📅")}
          </TabsContent>

          <TabsContent value="completed" className="mt-0">
            {renderTaskList(completedTasks, "No completed tasks yet. Get started! 💪")}
          </TabsContent>
        </Tabs>
      </div>

      {/* Floating Action Button */}
      <motion.div
        initial={{ scale: 0 }}
        animate={{ scale: 1 }}
        transition={{ delay: 0.3, type: "spring", stiffness: 260, damping: 20 }}
        className="fixed bottom-8 right-8 z-50"
      >
        <Button
          size="lg"
          onClick={handleOpenDialog}
          className="h-14 w-14 rounded-full shadow-lg bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 hover:scale-110 transition-transform"
        >
          <Plus className="h-6 w-6" />
        </Button>
      </motion.div>

      {/* Add/Edit Task Dialog */}
      <AddTaskDialog
        open={dialogOpen}
        onOpenChange={(open) => {
          setDialogOpen(open);
          if (!open) setEditingTask(null);
        }}
        onSave={handleSaveTask}
        editingTask={editingTask}
      />

      {/* Toast Notifications */}
      <Toaster position="top-center" richColors />
    </div>
  );
}

export default App;
