import { useState } from "react";
import { Checkbox } from "./ui/checkbox";
import { Button } from "./ui/button";
import { Badge } from "./ui/badge";
import { 
  Trash2, 
  Edit2, 
  Calendar, 
  Bell, 
  Clock,
  MoreVertical 
} from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "./ui/dropdown-menu";
import { motion } from "motion/react";
import { format, isPast, isToday } from "date-fns";

export interface Reminder {
  id: string;
  minutesBefore: number;
  label: string;
}

export interface Task {
  id: string;
  title: string;
  description?: string;
  dueDate?: Date;
  reminders: Reminder[];
  completed: boolean;
  createdAt: Date;
}

interface TaskItemProps {
  task: Task;
  onToggleComplete: (id: string) => void;
  onEdit: (task: Task) => void;
  onDelete: (id: string) => void;
}

export function TaskItem({ task, onToggleComplete, onEdit, onDelete }: TaskItemProps) {
  const [isAnimating, setIsAnimating] = useState(false);
  
  const isOverdue = task.dueDate && isPast(task.dueDate) && !task.completed;
  const isDueToday = task.dueDate && isToday(task.dueDate);

  const handleCheckboxChange = () => {
    setIsAnimating(true);
    setTimeout(() => {
      onToggleComplete(task.id);
      setIsAnimating(false);
    }, 300);
  };

  return (
    <motion.div
      layout
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, x: -100 }}
      className={`
        bg-white rounded-2xl p-4 shadow-sm border
        ${isOverdue ? 'border-red-200 bg-red-50/50' : 'border-gray-100'}
        ${task.completed ? 'opacity-60' : ''}
        transition-all duration-300
      `}
    >
      <div className="flex items-start gap-3">
        <motion.div
          animate={isAnimating ? { scale: [1, 1.2, 1] } : {}}
          transition={{ duration: 0.3 }}
        >
          <Checkbox
            checked={task.completed}
            onCheckedChange={handleCheckboxChange}
            className="mt-1 h-5 w-5 rounded-full"
          />
        </motion.div>

        <div className="flex-1 min-w-0">
          <div className="flex items-start justify-between gap-2">
            <div className="flex-1">
              <h3 
                className={`
                  font-medium text-gray-900 leading-snug
                  ${task.completed ? 'line-through text-gray-400' : ''}
                `}
              >
                {task.title}
              </h3>
              {task.description && (
                <p className="text-sm text-gray-500 mt-1 leading-relaxed">
                  {task.description}
                </p>
              )}
            </div>

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button 
                  variant="ghost" 
                  size="sm" 
                  className="h-8 w-8 p-0 rounded-full"
                >
                  <MoreVertical className="h-4 w-4 text-gray-400" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => onEdit(task)}>
                  <Edit2 className="h-4 w-4 mr-2" />
                  Edit
                </DropdownMenuItem>
                <DropdownMenuItem 
                  onClick={() => onDelete(task.id)}
                  className="text-red-600"
                >
                  <Trash2 className="h-4 w-4 mr-2" />
                  Delete
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>

          <div className="flex flex-wrap items-center gap-2 mt-3">
            {task.dueDate && (
              <Badge 
                variant="outline" 
                className={`
                  flex items-center gap-1 rounded-full px-2.5 py-1
                  ${isOverdue ? 'bg-red-100 text-red-700 border-red-200' : ''}
                  ${isDueToday ? 'bg-blue-100 text-blue-700 border-blue-200' : ''}
                `}
              >
                <Calendar className="h-3 w-3" />
                <span className="text-xs">
                  {isToday(task.dueDate) ? 'Today' : format(task.dueDate, 'MMM d')}
                </span>
                {task.dueDate && (
                  <>
                    <Clock className="h-3 w-3 ml-1" />
                    <span className="text-xs">
                      {format(task.dueDate, 'h:mm a')}
                    </span>
                  </>
                )}
              </Badge>
            )}

            {task.reminders.length > 0 && (
              <Badge 
                variant="outline" 
                className="flex items-center gap-1 rounded-full px-2.5 py-1 bg-purple-50 text-purple-700 border-purple-200"
              >
                <Bell className="h-3 w-3" />
                <span className="text-xs">{task.reminders.length} reminder{task.reminders.length > 1 ? 's' : ''}</span>
              </Badge>
            )}

            {isOverdue && (
              <Badge className="rounded-full px-2.5 py-1 bg-red-600 text-white text-xs">
                Overdue
              </Badge>
            )}
          </div>
        </div>
      </div>
    </motion.div>
  );
}
